<?php

/**
 * Proxmox Resources widget view.
 * By LAKA Soluciones Tecnológicas
 *  
 * @var CView $this
 * @var array $data
 */

$pve = $data['data'];

if (!empty($pve['error'])) {
	(new CWidgetView($data))
		->addItem(
			(new CDiv([
				(new CDiv('No se pudo cargar Proxmox Resources'))->addClass('pve-error-title'),
				(new CDiv($pve['error']))->addClass('pve-error-detail')
			]))->addClass('pve-error')
		)
		->show();
	return;
}

$esc = static function($value): string {
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$metric = static function(array $entity, string $name) {
	return $entity['metrics'][$name]['value'] ?? null;
};

$pct = static function(array $entity, string $direct, string $used, string $total) use ($metric): ?float {
	$value = $metric($entity, $direct);
	if ($value !== null && $value !== '') {
		return max(0, min(100, (float) $value));
	}
	$used_value = $metric($entity, $used);
	$total_value = $metric($entity, $total);
	if ($used_value === null || !$total_value) {
		return null;
	}
	return max(0, min(100, (float) $used_value / (float) $total_value * 100));
};

$bytes = static function($value): string {
	if ($value === null || $value === '' || !is_numeric($value)) {
		return '—';
	}
	$value = (float) $value;
	$units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
	$index = 0;
	while (abs($value) >= 1024 && $index < count($units) - 1) {
		$value /= 1024;
		$index++;
	}
	return number_format($value, $index > 1 ? 1 : 0, '.', '').' '.$units[$index];
};

$rate = static function($value): string {
	if ($value === null || $value === '' || !is_numeric($value)) {
		return '—';
	}
	$value = (float) $value;
	$units = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
	$index = 0;
	while (abs($value) >= 1000 && $index < count($units) - 1) {
		$value /= 1000;
		$index++;
	}
	return number_format($value, $index > 1 ? 1 : 0, '.', '').' '.$units[$index];
};

$byte_rate = static function($value): string {
	if ($value === null || $value === '' || !is_numeric($value)) {
		return '—';
	}
	$value = (float) $value;
	$units = ['B/s', 'KB/s', 'MB/s', 'GB/s', 'TB/s'];
	$index = 0;
	while (abs($value) >= 1024 && $index < count($units) - 1) {
		$value /= 1024;
		$index++;
	}
	return number_format($value, $index > 1 ? 1 : 0, '.', '').' '.$units[$index];
};

$duration = static function($seconds): string {
	if ($seconds === null || $seconds === '' || !is_numeric($seconds)) {
		return '—';
	}
	$seconds = max(0, (int) $seconds);
	$days = intdiv($seconds, 86400);
	$hours = intdiv($seconds % 86400, 3600);
	$minutes = intdiv($seconds % 3600, 60);
	return ($days > 0 ? $days.'d ' : '').sprintf('%02dh %02dm', $hours, $minutes);
};

$status = static function($value): array {
	$normalized = strtolower(trim((string) $value));
	if (in_array($normalized, ['1', '2', '10', 'online', 'running', 'active', 'ok'], true)) {
		return ['up', 'En línea'];
	}
	if (in_array($normalized, ['0', '20', 'offline', 'stopped', 'paused'], true)) {
		return ['down', $normalized === 'paused' ? 'Pausado' : 'Detenido'];
	}
	return ['unknown', 'Sin datos'];
};

$bar = static function(string $label, ?float $value, string $detail = '') use ($esc): CTag {
	$shown = $value === null ? '—' : number_format($value, 1).'%';
	$level = $value === null ? 'unknown' : ($value >= 90 ? 'critical' : ($value >= 75 ? 'warning' : 'ok'));
	$fill = (new CDiv())->addClass('pve-progress-fill pve-'.$level);
	$fill->setAttribute('style', 'width:'.($value === null ? 0 : $value).'%');
	return (new CTag('div', true, [
		(new CDiv([
			(new CSpan($label))->addClass('pve-progress-label'),
			(new CSpan($shown))->addClass('pve-progress-value')
		]))->addClass('pve-progress-head'),
		(new CDiv($fill))->addClass('pve-progress-track'),
		$detail !== '' ? (new CDiv($detail))->addClass('pve-progress-detail') : null
	]))->addClass('pve-resource');
};

$chartify = static function(CTag $element, array $points, string $title, string $resource,
		string $detail = '', string $used = '—', string $total = '—', string $itemid = '',
		?float $divisor = null) use ($pve): CTag {
	$has_values = false;
	foreach ($points as $point) {
		if ($point !== null) {
			$has_values = true;
			break;
		}
	}
	if (!$has_values && $itemid === '') {
		return $element;
	}
	$element
		->addClass('pve-chart-trigger')
		->setAttribute('tabindex', '0')
		->setAttribute('role', 'button')
		->setAttribute('title', 'Clic para abrir la gráfica histórica')
		->setAttribute('data-pve-history', base64_encode((string) json_encode($points)))
		->setAttribute('data-pve-chart-title', $title)
		->setAttribute('data-pve-chart-resource', $resource)
		->setAttribute('data-pve-chart-detail', $detail)
		->setAttribute('data-pve-chart-used', $used)
		->setAttribute('data-pve-chart-total', $total)
		->setAttribute('data-pve-history-itemid', $itemid)
		->setAttribute('data-pve-history-divisor', $divisor !== null ? (string) $divisor : '')
		->setAttribute('data-pve-chart-percent', '1')
		->setAttribute('data-pve-chart-default-hours', (string) ($pve['history_hours'] ?? 1))
		->setAttribute('data-pve-chart-from', (string) ($pve['history_from'] ?? 0))
		->setAttribute('data-pve-chart-bucket', (string) ($pve['history_bucket_seconds'] ?? 0));
	return $element;
};

$networkify = static function(CTag $element, array $input, array $output, string $title, string $detail = '',
		string $input_itemid = '', string $output_itemid = '') use ($pve): CTag {
	$has_values = false;
	foreach (array_merge($input, $output) as $point) {
		if ($point !== null) {
			$has_values = true;
			break;
		}
	}
	if (!$has_values && $input_itemid === '' && $output_itemid === '') {
		return $element;
	}
	if ($input_itemid === '' && $output_itemid !== '') {
		$input_itemid = $output_itemid;
		$output_itemid = '';
	}
	return $element
		->addClass('pve-chart-trigger')
		->setAttribute('tabindex', '0')
		->setAttribute('role', 'button')
		->setAttribute('title', 'Clic para abrir el histórico de red')
		->setAttribute('data-pve-history', base64_encode((string) json_encode($input)))
		->setAttribute('data-pve-history-secondary', base64_encode((string) json_encode($output)))
		->setAttribute('data-pve-history-itemid', $input_itemid)
		->setAttribute('data-pve-history-secondary-itemid', $output_itemid)
		->setAttribute('data-pve-chart-title', $title)
		->setAttribute('data-pve-chart-resource', 'Red E/S')
		->setAttribute('data-pve-chart-detail', $detail)
		->setAttribute('data-pve-chart-format', 'rate')
		->setAttribute('data-pve-chart-primary-label', 'Descarga')
		->setAttribute('data-pve-chart-secondary-label', 'Subida')
		->setAttribute('data-pve-chart-default-hours', (string) ($pve['history_hours'] ?? 1))
		->setAttribute('data-pve-chart-from', (string) ($pve['history_from'] ?? 0))
		->setAttribute('data-pve-chart-bucket', (string) ($pve['history_bucket_seconds'] ?? 0));
};

$view_mode = (int) ($pve['view_mode'] ?? 0);

$node_grids = [];
foreach ($pve['nodes'] as $node) {
	$node_hostid = (string) $node['hostid'];
	if (!isset($node_grids[$node_hostid])) {
		$node_grids[$node_hostid] = (new CDiv())->addClass('pve-node-grid');
	}
	[$status_class, $status_label] = $status($metric($node, 'status'));
	$cpu = $pct($node, 'cpu', 'cpu', 'cpu_total');
	$memory = $pct($node, 'memory_pct', 'memory_used', 'memory_total');
	$disk = $pct($node, 'disk_pct', 'disk_used', 'disk_total');
	$memory_detail = $bytes($metric($node, 'memory_used')).' / '.$bytes($metric($node, 'memory_total'));
	$disk_detail = $bytes($metric($node, 'disk_used')).' / '.$bytes($metric($node, 'disk_total'));
	$cpu_detail = $metric($node, 'cpu_count') !== null ? $metric($node, 'cpu_count').' CPU lógicas' : 'Uso actual del nodo';
	$node_cpu_total = $metric($node, 'cpu_count');
	$node_cpu_used = $cpu !== null && is_numeric($node_cpu_total)
		? number_format($cpu / 100 * (float) $node_cpu_total, 1).' CPU'
		: ($cpu !== null ? number_format($cpu, 1).'%' : '—');
	$updates = $metric($node, 'updates');
	$node_cpu_itemid = (string) ($node['metrics']['cpu']['itemid'] ?? '');
	$node_memory_metric = isset($node['metrics']['memory_pct']) ? 'memory_pct' : 'memory_used';
	$node_memory_itemid = (string) ($node['metrics'][$node_memory_metric]['itemid'] ?? '');
	$node_memory_divisor = $node_memory_metric === 'memory_used' && is_numeric($metric($node, 'memory_total'))
		? (float) $metric($node, 'memory_total')
		: null;

	$meta = (new CDiv())->addClass('pve-node-meta');
	foreach ([
		['IP', $metric($node, 'ip')],
		['PVE', $metric($node, 'version')],
		['Uptime', $duration($metric($node, 'uptime'))],
		['CPU', $metric($node, 'cpu_count') !== null ? $metric($node, 'cpu_count').' vCPU' : null]
	] as [$label, $value]) {
		if ($value !== null && $value !== '') {
			$meta->addItem((new CDiv([
				(new CSpan($label))->addClass('pve-meta-label'),
				new CSpan($value)
			]))->addClass('pve-meta-item'));
		}
	}

	$badges = (new CDiv())->addClass('pve-node-badges');
	$subscription = $metric($node, 'subscription');
	if ($subscription !== null && $subscription !== '') {
		$badges->addItem((new CSpan('Suscripción: '.$subscription))->addClass('pve-badge'));
	}
	if ($updates !== null && is_numeric($updates) && (int) $updates > 0) {
		$badges->addItem((new CSpan((int) $updates.' actualizaciones'))->addClass('pve-badge pve-badge-warning'));
	}

	$node_cpu_meter = $chartify(
		$bar('CPU', $cpu, $cpu_detail),
		$node['history']['cpu'] ?? [],
		$node['name'].' · Uso de CPU',
		'CPU',
		$cpu_detail,
		$node_cpu_used,
		$node_cpu_total !== null ? number_format((float) $node_cpu_total, 0).' CPU' : 'N/D',
		$node_cpu_itemid
	);
	$node_memory_meter = $chartify(
		$bar('Memoria', $memory, $memory_detail),
		$node['history']['memory'] ?? [],
		$node['name'].' · Uso de memoria RAM',
		'Memoria RAM',
		$memory_detail,
		$bytes($metric($node, 'memory_used')),
		$bytes($metric($node, 'memory_total')),
		$node_memory_itemid,
		$node_memory_divisor
	);

	$node_grids[$node_hostid]->addItem(
		(new CDiv([
			(new CDiv([
				(new CDiv([
					(new CSpan())->addClass('pve-status-dot pve-status-'.$status_class),
					(new CSpan($node['name']))->addClass('pve-node-name')
				]))->addClass('pve-node-title'),
				(new CSpan($status_label))->addClass('pve-status-pill pve-status-'.$status_class)
			]))->addClass('pve-node-head'),
			$meta,
			$badges,
			(new CDiv([
				$node_cpu_meter,
				$node_memory_meter,
				$bar('Disco raíz', $disk, $disk_detail)
			]))->addClass('pve-node-resources')
		]))->addClass('pve-node-card')
	);
}

$empty = static function(string $message): CDiv {
	return (new CDiv($message))->addClass('pve-empty');
};

$storage_tables = [];
foreach ($pve['storages'] as $storage) {
	$storage_hostid = (string) $storage['hostid'];
	if (!isset($storage_tables[$storage_hostid])) {
		$storage_tables[$storage_hostid] = (new CTableInfo())
			->setHeader(['Nodo', 'Almacenamiento', 'Tipo', 'Contenido', 'Utilización', 'Usado', 'Disponible', 'Total']);
	}
	$used = $metric($storage, 'used');
	$total = $metric($storage, 'total');
	$usage = $pct($storage, 'pct', 'used', 'total');
	$free = is_numeric($used) && is_numeric($total) && (float) $total > 0
		? max(0, (float) $total - (float) $used)
		: null;
	$storage_tables[$storage_hostid]->addRow([
		$storage['node'],
		(new CSpan($storage['name']))->addClass('pve-strong'),
		$metric($storage, 'type') ?: '—',
		$metric($storage, 'content') ?: '—',
		$bar('Uso', $usage),
		$bytes($used),
		(new CSpan($bytes($free)))->addClass($usage !== null && $usage >= 90 ? 'pve-text-critical' : 'pve-text-free'),
		$bytes($total)
	]);
}

$guest_table = static function(array $guests, string $kind, bool $focus_mode = false) use ($metric, $pct, $bar, $bytes, $rate, $byte_rate, $duration, $status, $chartify, $networkify): CTableInfo {
	if ($focus_mode) {
		usort($guests, static function(array $a, array $b) use ($metric, $pct, $status): int {
			$state_rank = ['up' => 0, 'down' => 1, 'unknown' => 2];
			$a_state = $status($metric($a, 'status'))[0];
			$b_state = $status($metric($b, 'status'))[0];
			$a_rank = $state_rank[$a_state] ?? 2;
			$b_rank = $state_rank[$b_state] ?? 2;
			if ($a_rank !== $b_rank) {
				return $a_rank <=> $b_rank;
			}
			if ($a_state === 'up') {
				$a_peak = max($pct($a, 'cpu', 'cpu', 'cpu_total') ?? 0, $pct($a, 'memory_pct', 'memory_used', 'memory_total') ?? 0);
				$b_peak = max($pct($b, 'cpu', 'cpu', 'cpu_total') ?? 0, $pct($b, 'memory_pct', 'memory_used', 'memory_total') ?? 0);
				if ($a_peak !== $b_peak) {
					return $b_peak <=> $a_peak;
				}
			}
			return strnatcasecmp($a['name'], $b['name']);
		});
	}
	$table = (new CTableInfo())->setHeader($focus_mode
		? ['Estado', 'Máquina virtual', 'Nodo', 'CPU', 'vCPU', 'Memoria RAM', 'RAM total', 'Disco E/S', 'Red E/S', 'Uptime']
		: ['Estado', 'Máquina virtual', 'Nodo', 'CPU', 'Memoria RAM', 'Disco E/S', 'Red E/S', 'Uptime']
	);
	if ($focus_mode) {
		$table->addClass('pve-vm-focus-table');
	}
	foreach ($guests as $guest) {
		[$state, $state_label] = $status($metric($guest, 'status'));
		$cpu = $pct($guest, 'cpu', 'cpu', 'cpu_total');
		$memory = $pct($guest, 'memory_pct', 'memory_used', 'memory_total');
		$disk = $pct($guest, 'disk_pct', 'disk_used', 'disk_total');
		$network_in = $metric($guest, 'network_in');
		$network_out = $metric($guest, 'network_out');
		$disk_read = $metric($guest, 'disk_read');
		$disk_write = $metric($guest, 'disk_write');
		$cpu_count = $metric($guest, 'cpu_count');
		$cpu_itemid = (string) ($guest['metrics']['cpu']['itemid'] ?? '');
		$memory_metric = isset($guest['metrics']['memory_pct']) ? 'memory_pct' : 'memory_used';
		$memory_itemid = (string) ($guest['metrics'][$memory_metric]['itemid'] ?? '');
		$memory_divisor = $memory_metric === 'memory_used' && is_numeric($metric($guest, 'memory_total'))
			? (float) $metric($guest, 'memory_total')
			: null;
		$network_in_itemid = (string) ($guest['metrics']['network_in']['itemid'] ?? '');
		$network_out_itemid = (string) ($guest['metrics']['network_out']['itemid'] ?? '');
		$health_class = '';
		if ($focus_mode && $state === 'up') {
			$peak = max($cpu ?? 0, $memory ?? 0);
			if ($peak >= 90) {
				$state_label = 'Crítica';
				$health_class = ' pve-health-critical';
			}
			elseif ($peak >= 75) {
				$state_label = 'Advertencia';
				$health_class = ' pve-health-warning';
			}
			else {
				$state_label = 'Normal';
				$health_class = ' pve-health-normal';
			}
		}
		$cpu_used = $cpu !== null && is_numeric($cpu_count)
			? number_format($cpu / 100 * (float) $cpu_count, 1).' vCPU'
			: ($cpu !== null ? number_format($cpu, 1).'%' : '—');
		$cpu_meter = $bar('CPU', $cpu, $focus_mode ? '' : ($cpu_count !== null ? $cpu_count.' vCPU asignadas' : 'Uso actual'));
		$cpu_meter->addClass('pve-guest-meter');
		$cpu_meter = $chartify(
			$cpu_meter,
			$guest['history']['cpu'] ?? [],
			$guest['name'].' · Uso de CPU',
			'CPU',
			'VMID '.$guest['id'].($cpu_count !== null ? ' · '.$cpu_count.' vCPU' : ''),
			$cpu_used,
			$cpu_count !== null ? number_format((float) $cpu_count, 0).' vCPU' : 'N/D',
			$cpu_itemid
		);
		$memory_meter = $bar(
			'RAM',
			$memory,
			$focus_mode ? $bytes($metric($guest, 'memory_used')).' usados' :
				$bytes($metric($guest, 'memory_used')).' de '.$bytes($metric($guest, 'memory_total'))
		);
		$memory_meter->addClass('pve-guest-meter');
		$memory_meter = $chartify(
			$memory_meter,
			$guest['history']['memory'] ?? [],
			$guest['name'].' · Uso de memoria RAM',
			'Memoria RAM',
			'VMID '.$guest['id'].' · '.$bytes($metric($guest, 'memory_used')).' de '.$bytes($metric($guest, 'memory_total')),
			$bytes($metric($guest, 'memory_used')),
			$bytes($metric($guest, 'memory_total')),
			$memory_itemid,
			$memory_divisor
		);
		if ($disk !== null) {
			$disk_cell = $bar(
				'Disco',
				$disk,
				$bytes($metric($guest, 'disk_used')).' de '.$bytes($metric($guest, 'disk_total'))
			);
			$disk_cell->addClass('pve-guest-meter');
		}
		else {
			$disk_cell = (new CDiv([
				(new CSpan('L '.$byte_rate($disk_read)))->addClass('pve-io-read'),
				(new CSpan('E '.$byte_rate($disk_write)))->addClass('pve-io-write')
			]))->addClass('pve-io-pair');
		}
		$network_cell = (new CDiv([
			(new CSpan('↓ '.$rate($network_in)))->addClass('pve-io-in'),
			(new CSpan('↑ '.$rate($network_out)))->addClass('pve-io-out')
		]))->addClass('pve-io-pair');
		$network_cell = $networkify(
			$network_cell,
			$guest['history']['network_in'] ?? [],
			$guest['history']['network_out'] ?? [],
			$guest['name'].' · Tráfico de red',
			'VMID '.$guest['id'].' · Nodo '.$guest['node'],
			$network_in_itemid,
			$network_out_itemid
		);
		$vm_detail = [
			'name' => $guest['name'], 'vmid' => $guest['id'], 'kind' => $kind, 'node' => $guest['node'],
			'state' => $state, 'status' => $state_label, 'cpu' => $cpu, 'vcpu' => $cpu_count,
			'memory' => $memory, 'memory_used' => $bytes($metric($guest, 'memory_used')),
			'memory_total' => $bytes($metric($guest, 'memory_total')), 'disk' => $disk,
			'disk_used' => $bytes($metric($guest, 'disk_used')), 'disk_total' => $bytes($metric($guest, 'disk_total')),
			'disk_read' => $byte_rate($disk_read), 'disk_write' => $byte_rate($disk_write),
			'network_in' => $rate($network_in), 'network_out' => $rate($network_out),
			'uptime' => $duration($metric($guest, 'uptime')),
			'agent' => $metric($guest, 'agent') === null ? 'N/D' : ((string) $metric($guest, 'agent') === '1' ? 'Activo' : 'No disponible')
		];
		$identity = (new CDiv([
			(new CDiv($guest['name']))->addClass('pve-vm-name'),
			(new CDiv([
				new CSpan('VMID '.$guest['id'].' · '.$kind),
				$metric($guest, 'agent') !== null
					? (new CSpan((string) $metric($guest, 'agent') === '1' ? ' · Agent activo' : ' · Agent no disponible'))
						->addClass((string) $metric($guest, 'agent') === '1' ? 'pve-agent-ok' : 'pve-agent-warn')
					: null
			]))->addClass('pve-vm-id')
		]))->addClass('pve-vm-identity pve-vm-summary-trigger')
			->setAttribute('tabindex', '0')
			->setAttribute('role', 'button')
			->setAttribute('title', 'Clic para ver el resumen de recursos')
			->setAttribute('data-pve-vm-summary', base64_encode((string) json_encode($vm_detail)));

		$row = [
			(new CSpan($state_label))->addClass('pve-status-pill pve-status-'.$state.$health_class),
			$identity,
			$guest['node'],
			$cpu_meter
		];
		if ($focus_mode) {
			$row[] = $cpu_count !== null ? number_format((float) $cpu_count, 0) : '—';
		}
		$row[] = $memory_meter;
		if ($focus_mode) {
			$row[] = $bytes($metric($guest, 'memory_total'));
		}
		$row[] = $disk_cell;
		$row[] = $network_cell;
		$row[] = $duration($metric($guest, 'uptime'));
		$table->addRow($row);
	}
	return $table;
};

$summary_card = static function(string $label, string $value, string $hint, string $icon): CDiv {
	return (new CDiv([
		(new CDiv($label))->addClass('pve-summary-label'),
		(new CDiv($value))->addClass('pve-summary-value'),
		(new CDiv($hint))->addClass('pve-summary-hint')
	]))->addClass('pve-summary-card pve-summary-'.$icon);
};

$build_summary = static function(array $nodes, array $storages, array $qemu, array $lxc, array $cluster, int $mode)
		use ($metric, $pct, $status, $bytes, $summary_card): CDiv {
	$summary = (new CDiv())->addClass('pve-summary'.($mode === 1 ? ' pve-vm-summary' : ''));
	$nodes_online = 0;
	$cpu_values = [];
	$memory_values = [];
	$disk_values = [];
	foreach ($nodes as $node) {
		if ($status($metric($node, 'status'))[0] === 'up') {
			$nodes_online++;
		}
		$cpu_value = $pct($node, 'cpu', 'cpu', 'cpu_total');
		$memory_value = $pct($node, 'memory_pct', 'memory_used', 'memory_total');
		$disk_value = $pct($node, 'disk_pct', 'disk_used', 'disk_total');
		if ($cpu_value !== null) {
			$cpu_values[] = $cpu_value;
		}
		if ($memory_value !== null) {
			$memory_values[] = $memory_value;
		}
		if ($disk_value !== null) {
			$disk_values[] = $disk_value;
		}
	}

	$qemu_counts = ['up' => 0, 'down' => 0, 'warning' => 0, 'critical' => 0, 'unknown' => 0];
	foreach ($qemu as $guest) {
		$state = $status($metric($guest, 'status'))[0];
		$peak = max(
			$pct($guest, 'cpu', 'cpu', 'cpu_total') ?? 0,
			$pct($guest, 'memory_pct', 'memory_used', 'memory_total') ?? 0
		);
		$qemu_counts[isset($qemu_counts[$state]) ? $state : 'unknown']++;
		if ($state === 'up' && $peak >= 90) {
			$qemu_counts['critical']++;
		}
		elseif ($state === 'up' && $peak >= 75) {
			$qemu_counts['warning']++;
		}
	}

	if ($mode === 1) {
		$cards = [
			['TOTAL', count($qemu), 'máquinas QEMU', 'allocation'],
			['EN LÍNEA', $qemu_counts['up'], 'máquinas ejecutándose', 'cluster'],
			['DETENIDAS', $qemu_counts['down'], 'máquinas apagadas', 'down'],
			['ADVERTENCIAS', $qemu_counts['warning'], 'CPU o RAM ≥ 75%', 'warning'],
			['CRÍTICAS', $qemu_counts['critical'], 'CPU o RAM ≥ 90%', 'critical'],
			['SIN DATOS', $qemu_counts['unknown'], 'estado no disponible', 'unknown']
		];
	}
	elseif ($mode === 2) {
		$cards = [
			['NODOS', $nodes_online.'/'.count($nodes), 'nodos en línea', 'nodes'],
			['STORAGE', count($storages), 'almacenamientos', 'storage'],
			['CPU PROMEDIO', $cpu_values ? number_format(array_sum($cpu_values) / count($cpu_values), 1).'%' : '—', 'uso actual de nodos', 'qemu'],
			['RAM PROMEDIO', $memory_values ? number_format(array_sum($memory_values) / count($memory_values), 1).'%' : '—', 'uso actual de nodos', 'allocation'],
			['DISCO RAÍZ', $disk_values ? number_format(array_sum($disk_values) / count($disk_values), 1).'%' : '—', 'utilización promedio', 'storage']
		];
	}
	else {
		$qemu_online = $qemu_counts['up'];
		$lxc_online = 0;
		foreach ($lxc as $guest) {
			if ($status($metric($guest, 'status'))[0] === 'up') {
				$lxc_online++;
			}
		}
		$physical_memory = 0.0;
		$assigned_memory = 0.0;
		foreach ($nodes as $node) {
			$physical_memory += (float) ($metric($node, 'memory_total') ?? 0);
		}
		foreach (array_merge($qemu, $lxc) as $guest) {
			$assigned_memory += (float) ($metric($guest, 'memory_total') ?? 0);
		}
		$allocation = $physical_memory > 0 ? $assigned_memory / $physical_memory * 100 : null;
		$cards = [
			['NODOS', $nodes_online.'/'.count($nodes), 'nodos en línea', 'nodes'],
			['QEMU', $qemu_online.'/'.count($qemu), 'máquinas ejecutándose', 'qemu'],
			['LXC', $lxc_online.'/'.count($lxc), 'contenedores activos', 'lxc'],
			['STORAGE', count($storages), 'almacenamientos', 'storage'],
			['RAM ASIGNADA', $allocation !== null ? number_format($allocation, 1).'%' : '—',
				$allocation !== null ? $bytes($assigned_memory).' de '.$bytes($physical_memory) : 'capacidad no disponible',
				$allocation !== null && $allocation > 100 ? 'allocation alert' : 'allocation']
		];
	}
	$quorate = $cluster['quorate']['value'] ?? null;
	if ($mode !== 1 && $quorate !== null) {
		$cards[] = [
			'CLÚSTER',
			(string) $quorate === '1' ? 'Quorum OK' : 'Sin quorum',
			(string) $quorate === '1' ? 'operación consistente' : 'requiere atención',
			(string) $quorate === '1' ? 'cluster' : 'cluster alert'
		];
	}

	foreach ($cards as [$label, $value, $hint, $icon]) {
		$summary->addItem($summary_card($label, (string) $value, $hint, $icon));
	}
	return $summary;
};

$build_vm_toolbar = static function(int $count, int $page_size, string $default_filter): CDiv {
	$search = (new CTag('input', false))
		->addClass('pve-vm-search')
		->setAttribute('type', 'search')
		->setAttribute('placeholder', 'Buscar VM, VMID o nodo...')
		->setAttribute('aria-label', 'Buscar máquinas virtuales');
	$filters = (new CDiv())->addClass('pve-vm-filters');
	foreach ([
		['all', 'Todas'], ['up', 'En línea'], ['down', 'Detenidas'],
		['critical', 'Críticas'], ['warning', 'Advertencias'], ['unknown', 'Sin datos']
	] as [$filter, $label]) {
		$filters->addItem(
			(new CTag('button', true, $label))
				->addClass('pve-vm-filter'.($filter === $default_filter ? ' is-active' : ''))
				->setAttribute('type', 'button')
				->setAttribute('data-pve-vm-filter', $filter)
				->setAttribute('aria-pressed', $filter === $default_filter ? 'true' : 'false')
		);
	}
	$page_size_options = [];
	foreach ([25, 50, 100] as $size) {
		$option = (new CTag('option', true, $size.' por página'))->setAttribute('value', (string) $size);
		if ($size === $page_size) {
			$option->setAttribute('selected', 'selected');
		}
		$page_size_options[] = $option;
	}
	$page_size_select = (new CTag('select', true, $page_size_options))
		->addClass('pve-vm-page-size')
		->setAttribute('aria-label', 'Máquinas virtuales por página');
	$pagination = (new CDiv([
		$page_size_select,
		(new CTag('button', true, '‹'))->addClass('pve-vm-page-button')->setAttribute('type', 'button')
			->setAttribute('data-pve-vm-page', 'previous')->setAttribute('aria-label', 'Página anterior'),
		(new CSpan('1 / 1'))->addClass('pve-vm-page-info'),
		(new CTag('button', true, '›'))->addClass('pve-vm-page-button')->setAttribute('type', 'button')
			->setAttribute('data-pve-vm-page', 'next')->setAttribute('aria-label', 'Página siguiente')
	]))->addClass('pve-vm-pagination');
	return (new CDiv([
		(new CDiv([
			(new CSpan('⌕'))->addClass('pve-vm-search-icon'),
			$search,
			(new CSpan($count.' VM'))->addClass('pve-vm-result-count')
		]))->addClass('pve-vm-search-wrap'),
		(new CDiv([$filters, $pagination]))->addClass('pve-vm-toolbar-actions')
	]))->addClass('pve-vm-toolbar');
};

$infrastructure_groups = (new CDiv())->addClass('pve-infrastructure-groups');
$group_hosts = $pve['hosts'];
uasort($group_hosts, static function(array $a, array $b): int {
	return strnatcasecmp($a['name'], $b['name']);
});
if ($view_mode !== 1) {
foreach ($group_hosts as $hostid => $host) {
	$hostid = (string) $hostid;
	$host_nodes = array_values(array_filter($pve['nodes'], static function(array $entity) use ($hostid): bool {
		return (string) $entity['hostid'] === $hostid;
	}));
	$host_storages = array_values(array_filter($pve['storages'], static function(array $entity) use ($hostid): bool {
		return (string) $entity['hostid'] === $hostid;
	}));
	$host_cluster = $pve['cluster'][$hostid] ?? [];

	$group_body = [
		(new CDiv([
			(new CDiv('Nodos Proxmox'))->addClass('pve-section-title'),
			$host_nodes
				? $node_grids[$hostid]
				: $empty('No se encontraron nodos para esta infraestructura.')
		]))->addClass('pve-panel is-active'),
		(new CDiv([
			(new CDiv('Almacenamiento'))->addClass('pve-section-title'),
			$host_storages
				? $storage_tables[$hostid]
				: $empty('No hay datos de almacenamiento descubiertos en esta infraestructura.')
		]))->addClass('pve-panel is-active')
	];

	$infrastructure_groups->addItem(
		(new CDiv([
			(new CDiv([
				(new CDiv([
					(new CSpan('PVE'))->addClass('pve-infrastructure-icon'),
					(new CDiv([
						(new CDiv($host['name']))->addClass('pve-infrastructure-name'),
						(new CDiv($host['host']))->addClass('pve-infrastructure-host')
					]))
				]))->addClass('pve-infrastructure-identity'),
				(new CDiv([
					new CSpan(count($host_nodes).' nodo(s)'),
					new CSpan(count($host_storages).' storage')
				]))->addClass('pve-infrastructure-meta')
			]))->addClass('pve-infrastructure-head'),
			$build_summary($host_nodes, $host_storages, [], [], $host_cluster, 2),
			(new CDiv($group_body))->addClass('pve-panels')
		]))->addClass('pve-infrastructure-card')
	);
}
}
if ($view_mode !== 1 && !$pve['hosts']) {
	$infrastructure_groups->addItem(
		$empty('No se encontraron hosts Proxmox. Seleccione uno o más hosts vinculados a la plantilla oficial.')
	);
}

$virtualization = null;
if ($view_mode !== 2) {
	$vm_panels = [
		$build_summary([], [], $pve['qemu'], [], [], 1),
		$build_vm_toolbar(count($pve['qemu']), (int) ($pve['vm_page_size'] ?? 50),
			(string) ($pve['vm_default_filter'] ?? 'up')),
		(new CDiv([
			(new CDiv([
				(new CDiv('Máquinas virtuales QEMU'))->addClass('pve-vm-group-title'),
				(new CSpan((string) count($pve['qemu'])))->addClass('pve-vm-group-count')
			]))->addClass('pve-vm-group-head'),
			$pve['qemu']
				? $guest_table($pve['qemu'], 'QEMU', true)
				: $empty('No hay máquinas virtuales QEMU descubiertas.')
		]))->addClass('pve-vm-focus-panel')
	];
	if ($view_mode === 0 && $pve['lxc']) {
		$vm_panels[] = (new CDiv([
			(new CDiv('Contenedores LXC'))->addClass('pve-section-title'),
			$guest_table($pve['lxc'], 'LXC')
		]))->addClass('pve-panel is-active pve-global-lxc');
	}
	$visible_vm_rows = (int) ($pve['visible_vm_rows'] ?? 12);
	$vm_panel_height = 76 + $visible_vm_rows * 56;
	$virtualization = (new CDiv($vm_panels))
		->addClass('pve-global-vms')
		->setAttribute('style', '--pve-vm-panel-height:'.$vm_panel_height.'px');
}

$family = isset($pve['families']['current']) && isset($pve['families']['legacy'])
	? 'Plantillas actual y heredada'
	: (isset($pve['families']['current']) ? 'Plantilla oficial actual' : 'Plantilla oficial heredada');
$updated = $pve['lastclock'] ? date('Y-m-d H:i:s', $pve['lastclock']) : 'sin datos';

$mode_title = $view_mode === 2
	? 'Infraestructura Proxmox'
	: ($view_mode === 1 ? 'Máquinas virtuales Proxmox' : 'Infraestructura y VMs Proxmox');
$root = (new CDiv([
	(new CDiv([
		(new CDiv([
			(new CSpan('PVE'))->addClass('pve-logo'),
			(new CDiv([
				(new CDiv($mode_title))->addClass('pve-title'),
				(new CDiv(count($pve['hosts']).' host(s) Zabbix · '.$family))->addClass('pve-subtitle')
			]))
		]))->addClass('pve-heading'),
		(new CDiv('Actualizado: '.$updated))->addClass('pve-updated')
	]))->addClass('pve-topbar'),
	$view_mode !== 1 ? $infrastructure_groups : null,
	$virtualization
]))->addClass('pve-dashboard'.($view_mode === 1 ? ' pve-vm-only' : ''))
	->setAttribute('data-pve-default-filter', (string) ($pve['vm_default_filter'] ?? 'up'));

(new CWidgetView($data))
	->addItem($root)
	->show();
