(function() {
	'use strict';

	if (window.pveHistoryModalInitialized) {
		return;
	}
	window.pveHistoryModalInitialized = true;

	const SVG_NS = 'http://www.w3.org/2000/svg';
	const PERIODS = [1, 3, 6, 12];
	const historyCache = new Map();

	function decodePoints(value) {
		try {
			return JSON.parse(atob(value)).map(point => point === null ? null : Number(point));
		}
		catch (error) {
			return [];
		}
	}

	function svgElement(name, attributes = {}) {
		const element = document.createElementNS(SVG_NS, name);
		for (const [key, value] of Object.entries(attributes)) {
			element.setAttribute(key, value);
		}
		return element;
	}

	function formatTime(timestamp) {
		return new Intl.DateTimeFormat(undefined, {
			month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit'
		}).format(new Date(timestamp * 1000));
	}

	function formatRate(value) {
		if (!Number.isFinite(value)) {
			return '—';
		}
		const units = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
		let index = 0;
		while (Math.abs(value) >= 1000 && index < units.length - 1) {
			value /= 1000;
			index++;
		}
		return `${value.toFixed(index > 1 ? 1 : 0)} ${units[index]}`;
	}

	function decodeObject(value) {
		try {
			return JSON.parse(atob(value));
		}
		catch (error) {
			return null;
		}
	}

	function rgbLuminance(color) {
		const match = color && color.match(/rgba?\((\d+)[, ]+\s*(\d+)[, ]+\s*(\d+)(?:[, /]+\s*([\d.]+))?\)/i);
		if (!match || (match[4] !== undefined && Number(match[4]) < 0.25)) {
			return null;
		}
		return (Number(match[1]) * 299 + Number(match[2]) * 587 + Number(match[3]) * 114) / 1000;
	}

	function usesDarkTheme(element) {
		const themeIdentity = `${document.documentElement.className} ${document.body ? document.body.className : ''} `
			+ `${document.documentElement.getAttribute('data-theme') || ''}`.toLowerCase();
		if (/(^|\s|[-_])(dark|hc-dark)(\s|$|[-_])/.test(themeIdentity)) {
			return true;
		}

		let current = element;
		while (current) {
			const luminance = rgbLuminance(getComputedStyle(current).backgroundColor);
			if (luminance !== null) {
				return luminance < 130;
			}
			current = current.parentElement;
		}
		return false;
	}

	function syncThemes() {
		document.querySelectorAll('.pve-dashboard').forEach(widget => {
			widget.classList.toggle('pve-is-dark', usesDarkTheme(widget));
		});
	}

	function vmRowStatus(row) {
		const status = row.querySelector('.pve-status-pill');
		if (!status || status.classList.contains('pve-status-unknown')) {
			return 'unknown';
		}
		if (status.classList.contains('pve-status-down')) {
			return 'down';
		}
		return 'up';
	}

	function vmRowHealth(row) {
		const identity = row.querySelector('.pve-vm-summary-trigger');
		const resource = identity ? decodeObject(identity.dataset.pveVmSummary || '') : null;
		if (resource) {
			const values = [resource.cpu, resource.memory]
				.filter(value => value !== null && value !== '' && Number.isFinite(Number(value)))
				.map(Number);
			const peak = values.length ? Math.max(...values) : 0;
			return peak >= 90 ? 'critical' : (peak >= 75 ? 'warning' : 'normal');
		}
		const values = Array.from(row.querySelectorAll('.pve-progress-value'))
			.map(element => Number.parseFloat(element.textContent))
			.filter(Number.isFinite);
		const peak = values.length ? Math.max(...values) : 0;
		if (peak >= 90) {
			return 'critical';
		}
		if (peak >= 75) {
			return 'warning';
		}
		return 'normal';
	}

	function applyVmFilters(scope, resetPage = false) {
		if (!scope) {
			return;
		}
		const search = scope.querySelector('.pve-vm-search');
		const query = (search ? search.value : '').trim().toLocaleLowerCase();
		const filter = scope.dataset.pveVmFilter || 'all';
		const rows = Array.from(scope.querySelectorAll('.pve-vm-focus-table tbody tr'));
		const matchingRows = [];
		for (const row of rows) {
			const matchesText = !query || row.textContent.toLocaleLowerCase().includes(query);
			const matchesState = filter === 'all'
				|| (filter === 'critical' || filter === 'warning'
					? vmRowStatus(row) === 'up' && vmRowHealth(row) === filter
					: vmRowStatus(row) === filter);
			if (matchesText && matchesState) {
				matchingRows.push(row);
			}
		}

		const pageSizeSelect = scope.querySelector('.pve-vm-page-size');
		const pageSize = Math.max(1, Number(pageSizeSelect ? pageSizeSelect.value : 50) || 50);
		const pageCount = Math.max(1, Math.ceil(matchingRows.length / pageSize));
		let page = resetPage ? 1 : Math.max(1, Number(scope.dataset.pveVmPage || 1));
		page = Math.min(page, pageCount);
		scope.dataset.pveVmPage = String(page);
		const firstIndex = (page - 1) * pageSize;
		const pageRows = new Set(matchingRows.slice(firstIndex, firstIndex + pageSize));
		for (const row of rows) {
			row.hidden = !pageRows.has(row);
		}

		const result = scope.querySelector('.pve-vm-result-count');
		if (result) {
			const firstShown = matchingRows.length ? firstIndex + 1 : 0;
			const lastShown = Math.min(firstIndex + pageSize, matchingRows.length);
			result.textContent = `${firstShown}-${lastShown} de ${matchingRows.length} VM`;
		}
		const pageInfo = scope.querySelector('.pve-vm-page-info');
		if (pageInfo) {
			pageInfo.textContent = `${page} / ${pageCount}`;
		}
		const previous = scope.querySelector('[data-pve-vm-page="previous"]');
		const next = scope.querySelector('[data-pve-vm-page="next"]');
		if (previous) {
			previous.disabled = page <= 1;
		}
		if (next) {
			next.disabled = page >= pageCount;
		}
		const panel = scope.querySelector('.pve-vm-focus-panel');
		if (panel) {
			panel.scrollTop = 0;
			let empty = panel.querySelector('.pve-vm-filter-empty');
			if (!empty) {
				empty = document.createElement('div');
				empty.className = 'pve-vm-filter-empty';
				empty.textContent = 'No hay máquinas virtuales que coincidan con el filtro.';
				panel.append(empty);
			}
			empty.hidden = matchingRows.length !== 0 || rows.length === 0;
		}
	}

	function initializeDashboards() {
		document.querySelectorAll('.pve-dashboard').forEach(scope => {
			const table = scope.querySelector('.pve-vm-focus-table');
			if (!table || table.dataset.pveVmInitialized === '1') {
				return;
			}
			table.dataset.pveVmInitialized = '1';
			scope.dataset.pveVmFilter = scope.dataset.pveDefaultFilter || 'up';
			scope.dataset.pveVmPage = '1';
			applyVmFilters(scope, true);
		});
	}

	let themeFrame = 0;
	function scheduleThemeSync() {
		if (!themeFrame) {
			themeFrame = requestAnimationFrame(() => {
				themeFrame = 0;
				syncThemes();
				initializeDashboards();
			});
		}
	}

	function closeModal(backdrop) {
		backdrop.remove();
		document.body.classList.remove('pve-modal-open');
	}

	function addStat(stats, label, value) {
		const card = document.createElement('div');
		card.className = 'pve-chart-stat';
		const statLabel = document.createElement('span');
		statLabel.textContent = label;
		const statValue = document.createElement('strong');
		statValue.textContent = value;
		card.append(statLabel, statValue);
		stats.append(card);
	}

	async function loadHistory(trigger) {
		const itemid = trigger.dataset.pveHistoryItemid || '';
		const secondaryItemid = trigger.dataset.pveHistorySecondaryItemid || '';
		if (!itemid) {
			throw new Error('No existe un ítem histórico asociado.');
		}
		const divisor = Number(trigger.dataset.pveHistoryDivisor || 0);
		const percent = trigger.dataset.pveChartPercent === '1';
		const cacheKey = `${itemid}:${secondaryItemid}:${divisor}:${percent ? 1 : 0}`;
		const cached = historyCache.get(cacheKey);
		if (cached && cached.expires > Date.now()) {
			return cached.data;
		}

		const url = new URL('zabbix.php', window.location.href);
		url.searchParams.set('action', 'widget.laka_proxmox_resources.history');
		url.searchParams.set('itemid', itemid);
		url.searchParams.set('hours', '12');
		if (secondaryItemid) {
			url.searchParams.set('secondary_itemid', secondaryItemid);
		}
		const response = await fetch(url.toString(), {
			credentials: 'same-origin',
			headers: {
				'Accept': 'application/json',
				'X-Requested-With': 'XMLHttpRequest'
			}
		});
		const responseText = await response.text();
		if (!response.ok) {
			throw new Error(`Error HTTP ${response.status}`);
		}
		let payload;
		try {
			payload = JSON.parse(responseText);
		}
		catch (error) {
			const isHtml = /^\s*<!doctype|^\s*<html/i.test(responseText);
			throw new Error(isHtml
				? 'Zabbix devolvió una página HTML. Vuelva a escanear y habilitar el módulo.'
				: 'La respuesta histórica de Zabbix no es JSON válido.');
		}
		if (payload.error) {
			throw new Error(payload.error);
		}
		const normalize = series => (Array.isArray(series) ? series : []).map(value => {
			if (value === null || !Number.isFinite(Number(value))) {
				return null;
			}
			let normalized = Number(value);
			if (divisor > 0) {
				normalized = normalized / divisor * 100;
			}
			return percent ? Math.max(0, Math.min(100, normalized)) : Math.max(0, normalized);
		});
		const result = {
			points: normalize(payload.series && payload.series[itemid]),
			secondary: normalize(payload.series && payload.series[secondaryItemid]),
			from: Number(payload.from || 0),
			bucket: Number(payload.bucket || 0)
		};
		historyCache.set(cacheKey, {expires: Date.now() + 60000, data: result});
		return result;
	}

	function openVmSummary(trigger) {
		const data = decodeObject(trigger.dataset.pveVmSummary || '');
		if (!data) {
			return;
		}
		const previous = document.querySelector('.pve-chart-modal-backdrop');
		if (previous) {
			closeModal(previous);
		}

		const backdrop = document.createElement('div');
		backdrop.className = 'pve-chart-modal-backdrop';
		const modal = document.createElement('div');
		modal.className = 'pve-chart-modal pve-vm-detail-modal';
		if (trigger.closest('.pve-is-dark') || usesDarkTheme(trigger)) {
			modal.classList.add('pve-is-dark');
		}
		modal.setAttribute('role', 'dialog');
		modal.setAttribute('aria-modal', 'true');
		modal.setAttribute('aria-label', `Resumen de ${data.name}`);

		const header = document.createElement('div');
		header.className = 'pve-chart-modal-header';
		const heading = document.createElement('div');
		const title = document.createElement('div');
		title.className = 'pve-chart-modal-title';
		title.textContent = data.name;
		const subtitle = document.createElement('div');
		subtitle.className = 'pve-chart-modal-subtitle';
		subtitle.textContent = `VMID ${data.vmid} · ${data.kind} · Nodo ${data.node}`;
		heading.append(title, subtitle);
		const close = document.createElement('button');
		close.type = 'button';
		close.className = 'pve-chart-modal-close';
		close.setAttribute('aria-label', 'Cerrar');
		close.textContent = '×';
		header.append(heading, close);

		const metadata = document.createElement('div');
		metadata.className = 'pve-vm-detail-grid';
		for (const [label, value] of [
			['Estado', data.status], ['Nodo', data.node], ['vCPU', data.vcpu ?? '—'],
			['Uptime', data.uptime], ['Guest Agent', data.agent]
		]) {
			addStat(metadata, label, String(value ?? '—'));
		}

		const resources = document.createElement('div');
		resources.className = 'pve-vm-detail-resources';
		for (const item of [
			['CPU', data.cpu, data.vcpu == null ? 'Capacidad no disponible' : `${data.vcpu} vCPU asignadas`],
			['Memoria RAM', data.memory, `${data.memory_used} de ${data.memory_total}`],
			['Disco', data.disk, `${data.disk_used} de ${data.disk_total}`]
		]) {
			const resource = document.createElement('div');
			resource.className = 'pve-vm-detail-resource';
			const top = document.createElement('div');
			top.className = 'pve-vm-detail-resource-head';
			const label = document.createElement('strong');
			label.textContent = item[0];
			const percent = document.createElement('strong');
			const hasNumericValue = item[1] !== null && item[1] !== '' && Number.isFinite(Number(item[1]));
			percent.textContent = hasNumericValue ? `${Number(item[1]).toFixed(1)}%` : '—';
			top.append(label, percent);
			const track = document.createElement('div');
			track.className = 'pve-vm-detail-track';
			const fill = document.createElement('div');
			const numeric = hasNumericValue ? Number(item[1]) : NaN;
			fill.className = `pve-vm-detail-fill ${numeric >= 90 ? 'is-critical' : (numeric >= 75 ? 'is-warning' : '')}`;
			fill.style.width = `${Number.isFinite(numeric) ? Math.max(0, Math.min(100, numeric)) : 0}%`;
			track.append(fill);
			const detail = document.createElement('div');
			detail.className = 'pve-chart-modal-subtitle';
			detail.textContent = item[2];
			resource.append(top, track, detail);
			resources.append(resource);
		}

		const io = document.createElement('div');
		io.className = 'pve-vm-detail-io';
		for (const [label, input, output] of [
			['Disco E/S', `Lectura ${data.disk_read}`, `Escritura ${data.disk_write}`],
			['Red E/S', `Descarga ${data.network_in}`, `Subida ${data.network_out}`]
		]) {
			const card = document.createElement('div');
			card.className = 'pve-vm-detail-io-card';
			const name = document.createElement('strong');
			name.textContent = label;
			const first = document.createElement('span');
			first.textContent = input;
			const second = document.createElement('span');
			second.textContent = output;
			card.append(name, first, second);
			io.append(card);
		}

		modal.append(header, metadata, resources, io);
		backdrop.append(modal);
		document.body.append(backdrop);
		document.body.classList.add('pve-modal-open');
		close.focus();
		close.addEventListener('click', () => closeModal(backdrop));
		backdrop.addEventListener('click', event => {
			if (event.target === backdrop) {
				closeModal(backdrop);
			}
		});
	}

	async function openModal(trigger) {
		let allPoints = decodePoints(trigger.dataset.pveHistory || '');
		let secondaryPoints = decodePoints(trigger.dataset.pveHistorySecondary || '');
		let allFrom = Number(trigger.dataset.pveChartFrom || 0);
		let bucket = Number(trigger.dataset.pveChartBucket || 0);
		let loadError = '';
		const hasEmbeddedValues = allPoints.some(value => value !== null && Number.isFinite(value))
			|| secondaryPoints.some(value => value !== null && Number.isFinite(value));
		if (!hasEmbeddedValues && trigger.dataset.pveHistoryItemid) {
			trigger.classList.add('pve-chart-loading');
			try {
				const loaded = await loadHistory(trigger);
				allPoints = loaded.points;
				secondaryPoints = loaded.secondary;
				allFrom = loaded.from;
				bucket = loaded.bucket;
			}
			catch (error) {
				loadError = error && error.message ? error.message : 'No fue posible cargar el histórico.';
			}
			finally {
				trigger.classList.remove('pve-chart-loading');
			}
		}
		let hasSecondary = secondaryPoints.some(value => value !== null && Number.isFinite(value));
		if (!allPoints.some(value => value !== null && Number.isFinite(value)) && !hasSecondary && !loadError
				&& !trigger.dataset.pveHistoryItemid) {
			return;
		}

		const previous = document.querySelector('.pve-chart-modal-backdrop');
		if (previous) {
			closeModal(previous);
		}

		const title = trigger.dataset.pveChartTitle || 'Histórico de recursos';
		const resource = trigger.dataset.pveChartResource || 'Recurso';
		const detail = trigger.dataset.pveChartDetail || '';
		const used = trigger.dataset.pveChartUsed || '—';
		const total = trigger.dataset.pveChartTotal || '—';
		const chartFormat = trigger.dataset.pveChartFormat || 'percent';
		const primaryLabel = trigger.dataset.pveChartPrimaryLabel || resource;
		const secondaryLabel = trigger.dataset.pveChartSecondaryLabel || '';
		let selectedHours = Number(trigger.dataset.pveChartDefaultHours || 1);
		if (!PERIODS.includes(selectedHours)) {
			selectedHours = 1;
		}

		const backdrop = document.createElement('div');
		backdrop.className = 'pve-chart-modal-backdrop';
		const modal = document.createElement('div');
		modal.className = 'pve-chart-modal';
		if (trigger.closest('.pve-is-dark') || usesDarkTheme(trigger)) {
			modal.classList.add('pve-is-dark');
		}
		modal.setAttribute('role', 'dialog');
		modal.setAttribute('aria-modal', 'true');
		modal.setAttribute('aria-label', title);

		const header = document.createElement('div');
		header.className = 'pve-chart-modal-header';
		const heading = document.createElement('div');
		const titleNode = document.createElement('div');
		titleNode.className = 'pve-chart-modal-title';
		titleNode.textContent = title;
		const subtitle = document.createElement('div');
		subtitle.className = 'pve-chart-modal-subtitle';
		subtitle.textContent = detail;
		heading.append(titleNode, subtitle);
		const close = document.createElement('button');
		close.type = 'button';
		close.className = 'pve-chart-modal-close';
		close.setAttribute('aria-label', 'Cerrar');
		close.textContent = '×';
		header.append(heading, close);

		const controls = document.createElement('div');
		controls.className = 'pve-chart-periods';
		const periodLabel = document.createElement('span');
		periodLabel.textContent = 'Periodo';
		controls.append(periodLabel);
		for (const hours of PERIODS) {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'pve-chart-period';
			button.dataset.hours = String(hours);
			button.textContent = `${hours} h`;
			button.setAttribute('aria-pressed', hours === selectedHours ? 'true' : 'false');
			controls.append(button);
		}

		const stats = document.createElement('div');
		stats.className = 'pve-chart-modal-stats';
		const chart = document.createElement('div');
		chart.className = 'pve-chart-large';

		function render(hours) {
			if (loadError) {
				stats.replaceChildren();
				chart.textContent = loadError;
				chart.classList.add('pve-chart-empty');
				return;
			}
			const seriesLength = Math.max(allPoints.length, secondaryPoints.length);
			const pointCount = bucket > 0 ? Math.ceil(hours * 3600 / bucket) : seriesLength;
			const startIndex = Math.max(0, seriesLength - pointCount);
			const align = series => Array(Math.max(0, seriesLength - series.length)).fill(null).concat(series).slice(startIndex);
			const points = align(allPoints);
			const secondary = hasSecondary ? align(secondaryPoints) : [];
			const from = allFrom && bucket ? allFrom + startIndex * bucket : 0;
			const values = points.filter(value => value !== null && Number.isFinite(value));
			const secondaryValues = secondary.filter(value => value !== null && Number.isFinite(value));
			if (!values.length && !secondaryValues.length) {
				stats.replaceChildren();
				chart.textContent = 'No hay datos históricos para este periodo.';
				chart.classList.add('pve-chart-empty');
				return;
			}

			chart.classList.remove('pve-chart-empty');
			const allValues = values.concat(secondaryValues);
			const average = values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : 0;
			const minimum = values.length ? Math.min(...values) : 0;
			const maximum = Math.max(...allValues);
			const current = values.length ? values[values.length - 1] : 0;
			const secondaryAverage = secondaryValues.length
				? secondaryValues.reduce((sum, value) => sum + value, 0) / secondaryValues.length : 0;
			const secondaryCurrent = secondaryValues.length ? secondaryValues[secondaryValues.length - 1] : 0;
			const chartMaximum = chartFormat === 'rate'
				? Math.max(1, maximum * 1.12)
				: Math.min(100, Math.max(20, Math.ceil(maximum / 10) * 10));
			const color = resource.toLowerCase().includes('memoria') ? '#ef6661' : '#28a9e2';
			const secondaryColor = '#a66bd0';
			const formatValue = value => chartFormat === 'rate' ? formatRate(value) : `${value.toFixed(1)}%`;

			stats.replaceChildren();
			const statisticValues = hasSecondary ? [
				[`Actual ${primaryLabel}`, formatValue(current)],
				[`Actual ${secondaryLabel}`, formatValue(secondaryCurrent)],
				[`Promedio ${primaryLabel}`, formatValue(average)],
				[`Promedio ${secondaryLabel}`, formatValue(secondaryAverage)],
				[`Máximo ${primaryLabel}`, formatValue(values.length ? Math.max(...values) : 0)],
				[`Máximo ${secondaryLabel}`, formatValue(secondaryValues.length ? Math.max(...secondaryValues) : 0)]
			] : [
				['Actual', formatValue(current)], ['Promedio', formatValue(average)],
				['Mínimo', formatValue(minimum)], ['Máximo', formatValue(maximum)],
				['Usado', used], ['Total', total]
			];
			for (const [label, value] of statisticValues) {
				addStat(stats, label, value);
			}

			const svg = svgElement('svg', {viewBox: '0 0 920 340', preserveAspectRatio: 'none'});
			const left = 55, right = 18, top = 20, bottom = 48;
			const width = 920 - left - right, height = 340 - top - bottom;

			for (let step = 0; step <= 4; step++) {
				const value = chartMaximum * step / 4;
				const y = top + height - height * step / 4;
				const line = svgElement('line', {x1: left, y1: y, x2: left + width, y2: y, class: 'pve-chart-grid'});
				const label = svgElement('text', {x: left - 8, y: y + 4, class: 'pve-chart-axis-label', 'text-anchor': 'end'});
				label.textContent = chartFormat === 'rate' ? formatRate(value) : `${value.toFixed(0)}%`;
				svg.append(line, label);
			}

			const coordinatesFor = series => series.map((value, index) => {
				if (value === null || !Number.isFinite(value)) {
					return null;
				}
				const x = left + (points.length === 1 ? 0 : index / (points.length - 1) * width);
				const y = top + height - Math.min(value, chartMaximum) / chartMaximum * height;
				return [x, y, value, index];
			}).filter(Boolean);

			const drawSeries = (series, seriesColor, withArea) => {
				const coordinates = coordinatesFor(series);
				if (!coordinates.length) {
					return;
				}
				const linePath = coordinates.map((point, index) => `${index ? 'L' : 'M'} ${point[0]} ${point[1]}`).join(' ');
				if (withArea) {
					const areaPath = `${linePath} L ${coordinates[coordinates.length - 1][0]} ${top + height} L ${coordinates[0][0]} ${top + height} Z`;
					svg.append(svgElement('path', {d: areaPath, fill: seriesColor, class: 'pve-chart-area'}));
				}
				svg.append(svgElement('path', {d: linePath, fill: 'none', stroke: seriesColor, class: 'pve-chart-line'}));
			};
			drawSeries(points, color, true);
			if (hasSecondary) {
				drawSeries(secondary, secondaryColor, false);
			}

			for (const [position, index] of [['start', 0], ['middle', Math.floor((points.length - 1) / 2)], ['end', points.length - 1]]) {
				const x = left + (points.length === 1 ? 0 : index / (points.length - 1) * width);
				const label = svgElement('text', {
					x, y: 326, class: 'pve-chart-axis-label',
					'text-anchor': position === 'start' ? 'start' : (position === 'end' ? 'end' : 'middle')
				});
				label.textContent = from && bucket ? formatTime(from + index * bucket) : '';
				svg.append(label);
			}

			const cursor = svgElement('line', {x1: 0, y1: top, x2: 0, y2: top + height, class: 'pve-chart-cursor'});
			const dot = svgElement('circle', {cx: 0, cy: 0, r: 5, fill: color, class: 'pve-chart-dot'});
			cursor.style.display = 'none';
			dot.style.display = 'none';
			svg.append(cursor, dot);

			const tooltip = document.createElement('div');
			tooltip.className = 'pve-chart-tooltip';
			tooltip.style.display = 'none';
			chart.replaceChildren(svg, tooltip);

			svg.addEventListener('mousemove', event => {
				const rect = svg.getBoundingClientRect();
				const chartX = (event.clientX - rect.left) / rect.width * 920;
				const index = Math.max(0, Math.min(points.length - 1, Math.round((chartX - left) / width * (points.length - 1))));
				const value = points[index];
				const secondaryValue = secondary[index];
				if ((value === null || !Number.isFinite(value))
						&& (secondaryValue === null || !Number.isFinite(secondaryValue))) {
					tooltip.style.display = 'none';
					cursor.style.display = 'none';
					dot.style.display = 'none';
					return;
				}
				const x = left + index / Math.max(1, points.length - 1) * width;
				const markerValue = value !== null && Number.isFinite(value) ? value : secondaryValue;
				const y = top + height - Math.min(markerValue, chartMaximum) / chartMaximum * height;
				cursor.setAttribute('x1', x);
				cursor.setAttribute('x2', x);
				dot.setAttribute('cx', x);
				dot.setAttribute('cy', y);
				cursor.style.display = '';
				dot.style.display = '';
				const lines = [];
				if (value !== null && Number.isFinite(value)) {
					lines.push(`${primaryLabel}: ${formatValue(value)}`);
				}
				if (secondaryValue !== null && Number.isFinite(secondaryValue)) {
					lines.push(`${secondaryLabel}: ${formatValue(secondaryValue)}`);
				}
				tooltip.textContent = `${from && bucket ? formatTime(from + index * bucket) + ' · ' : ''}${lines.join(' · ')}`;
				tooltip.style.display = 'block';
				tooltip.style.left = `${Math.min(rect.width - 205, Math.max(8, event.clientX - rect.left + 12))}px`;
				tooltip.style.top = `${Math.max(8, event.clientY - rect.top - 44)}px`;
			});
			svg.addEventListener('mouseleave', () => {
				tooltip.style.display = 'none';
				cursor.style.display = 'none';
				dot.style.display = 'none';
			});
		}

		controls.addEventListener('click', event => {
			const button = event.target.closest('.pve-chart-period');
			if (!button) {
				return;
			}
			selectedHours = Number(button.dataset.hours);
			controls.querySelectorAll('.pve-chart-period').forEach(option => {
				option.setAttribute('aria-pressed', option === button ? 'true' : 'false');
			});
			render(selectedHours);
		});

		modal.append(header, controls, stats, chart);
		backdrop.append(modal);
		document.body.append(backdrop);
		document.body.classList.add('pve-modal-open');
		render(selectedHours);
		close.focus();

		close.addEventListener('click', () => closeModal(backdrop));
		backdrop.addEventListener('click', event => {
			if (event.target === backdrop) {
				closeModal(backdrop);
			}
		});
	}

	document.addEventListener('click', event => {
		const trigger = event.target.closest && event.target.closest('.pve-chart-trigger');
		if (trigger) {
			openModal(trigger);
			return;
		}
		const vmTrigger = event.target.closest && event.target.closest('.pve-vm-summary-trigger');
		if (vmTrigger) {
			openVmSummary(vmTrigger);
			return;
		}
		const filterButton = event.target.closest && event.target.closest('.pve-vm-filter');
		if (filterButton) {
			const scope = filterButton.closest('.pve-infrastructure-card') || filterButton.closest('.pve-dashboard');
			scope.dataset.pveVmFilter = filterButton.dataset.pveVmFilter || 'all';
			scope.querySelectorAll('.pve-vm-filter').forEach(button => {
				const active = button === filterButton;
				button.classList.toggle('is-active', active);
				button.setAttribute('aria-pressed', active ? 'true' : 'false');
			});
			applyVmFilters(scope, true);
			return;
		}
		const pageButton = event.target.closest && event.target.closest('.pve-vm-page-button');
		if (pageButton && !pageButton.disabled) {
			const scope = pageButton.closest('.pve-dashboard');
			const current = Math.max(1, Number(scope.dataset.pveVmPage || 1));
			scope.dataset.pveVmPage = String(pageButton.dataset.pveVmPage === 'next' ? current + 1 : current - 1);
			applyVmFilters(scope);
		}
	});

	document.addEventListener('input', event => {
		if (event.target.matches && event.target.matches('.pve-vm-search')) {
			applyVmFilters(event.target.closest('.pve-infrastructure-card') || event.target.closest('.pve-dashboard'), true);
		}
	});

	document.addEventListener('change', event => {
		if (event.target.matches && event.target.matches('.pve-vm-page-size')) {
			applyVmFilters(event.target.closest('.pve-dashboard'), true);
		}
	});

	document.addEventListener('keydown', event => {
		if (event.key === 'Escape') {
			const modal = document.querySelector('.pve-chart-modal-backdrop');
			if (modal) {
				closeModal(modal);
			}
			return;
		}
		if (event.key === 'Enter' || event.key === ' ') {
			const trigger = event.target.closest && event.target.closest('.pve-chart-trigger');
			if (trigger) {
				event.preventDefault();
				openModal(trigger);
				return;
			}
			const vmTrigger = event.target.closest && event.target.closest('.pve-vm-summary-trigger');
			if (vmTrigger) {
				event.preventDefault();
				openVmSummary(vmTrigger);
			}
		}
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => {
			syncThemes();
			initializeDashboards();
		}, {once: true});
	}
	else {
		syncThemes();
		initializeDashboards();
	}

	new MutationObserver(scheduleThemeSync).observe(document.documentElement, {
		attributes: true,
		attributeFilter: ['class', 'data-theme'],
		childList: true,
		subtree: true
	});
})();
