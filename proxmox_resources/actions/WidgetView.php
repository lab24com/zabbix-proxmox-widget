<?php

namespace Modules\ProxmoxResources\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

	private const CURRENT_PREFIX = 'proxmox_ve.';
	private const LEGACY_PREFIX = 'proxmox.';

	protected function doAction(): void {
		try {
			$hostids = array_values(array_filter($this->fields_values['hostids'] ?? []));
			$view_mode = (int) ($this->fields_values['view_mode'] ?? 0);
			if (!in_array($view_mode, [0, 1, 2], true)) {
				$view_mode = 0;
			}
			$history_hours = (int) ($this->fields_values['history_hours'] ?? 1);
			if (!in_array($history_hours, [1, 3, 6, 12], true)) {
				$history_hours = 1;
			}
			$visible_vm_rows = (int) ($this->fields_values['visible_vm_rows'] ?? 12);
			if (!in_array($visible_vm_rows, [8, 12, 16, 20], true)) {
				$visible_vm_rows = 12;
			}
			$vm_page_size = (int) ($this->fields_values['vm_page_size'] ?? 50);
			if (!in_array($vm_page_size, [25, 50, 100], true)) {
				$vm_page_size = 50;
			}
			$vm_default_filter_value = (int) ($this->fields_values['vm_default_filter'] ?? 1);
			$vm_filter_map = [0 => 'all', 1 => 'up', 2 => 'down', 3 => 'critical', 4 => 'warning', 5 => 'unknown'];
			$vm_default_filter = $vm_filter_map[$vm_default_filter_value] ?? 'up';

			if (!$hostids) {
				$master_items = API::Item()->get([
					'output' => ['hostid'],
					'filter' => [
						'key_' => [
							'proxmox.cluster.resources',
							'proxmox_ve.get_node_data'
						]
					],
					'monitored' => true,
					'limit' => 100
				]);
				$hostids = array_values(array_unique(array_column($master_items, 'hostid')));
			}

			$items = [];
			$hosts = [];
			if ($hostids) {
				$hosts = API::Host()->get([
					'output' => ['hostid', 'host', 'name'],
					'hostids' => $hostids,
					'preservekeys' => true
				]);

				$items = API::Item()->get([
					'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type', 'state'],
					'hostids' => $hostids,
					'monitored' => true,
					'webitems' => true,
					'search' => ['key_' => 'proxmox'],
					'limit' => 20000
				]);
			}

			$data = $this->buildData($items, $hosts);
			$data['view_mode'] = $view_mode;
			$data['history_hours'] = $history_hours;
			$data['visible_vm_rows'] = $visible_vm_rows;
			$data['vm_page_size'] = $vm_page_size;
			$data['vm_default_filter'] = $vm_default_filter;
			$problems = [];

			$data['problems'] = $problems;
			$data['severity_counts'] = array_fill(0, 6, 0);
			foreach ($problems as $problem) {
				$severity = (int) $problem['severity'];
				if (isset($data['severity_counts'][$severity])) {
					$data['severity_counts'][$severity]++;
				}
			}
		}
		catch (\Throwable $exception) {
			$data = $this->emptyData();
			$data['error'] = $exception->getMessage();
		}

		$this->setResponse(new CControllerResponseData([
			'name' => $this->getInput('name', $this->widget->getName()),
			'data' => $data,
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}

	private function emptyData(): array {
		return [
			'hosts' => [],
			'nodes' => [],
			'storages' => [],
			'qemu' => [],
			'lxc' => [],
			'api' => [],
			'cluster' => [],
			'lastclock' => 0,
			'families' => [],
			'view_mode' => 0,
			'history_hours' => 1,
			'visible_vm_rows' => 12,
			'vm_page_size' => 50,
			'vm_default_filter' => 'up',
			'history_from' => 0,
			'history_bucket_seconds' => 0,
			'problems' => [],
			'severity_counts' => array_fill(0, 6, 0),
			'error' => ''
		];
	}

	private function buildData(array $items, array $hosts): array {
		$data = $this->emptyData();
		foreach ($hosts as $hostid => $host) {
			$data['hosts'][(string) $hostid] = [
				'hostid' => (string) $hostid,
				'name' => (string) ($host['name'] ?? $host['host'] ?? $hostid),
				'host' => (string) ($host['host'] ?? $hostid)
			];
		}

		foreach ($items as $item) {
			$key = (string) $item['key_'];
			if (strpos($key, self::CURRENT_PREFIX) !== 0 && strpos($key, self::LEGACY_PREFIX) !== 0) {
				continue;
			}

			$hostid = (string) $item['hostid'];
			if (!isset($data['hosts'][$hostid])) {
				$data['hosts'][$hostid] = ['hostid' => $hostid, 'name' => $hostid, 'host' => $hostid];
			}
			$data['lastclock'] = max($data['lastclock'], (int) $item['lastclock']);

			$current = strpos($key, self::CURRENT_PREFIX) === 0;
			$data['families'][$current ? 'current' : 'legacy'] = true;
			$args = $this->keyArguments($key);
			$metric = $this->metric($key);
			$value = [
				'value' => $item['lastvalue'],
				'clock' => (int) $item['lastclock'],
				'units' => (string) $item['units'],
				'value_type' => (int) $item['value_type'],
				'itemid' => (string) $item['itemid'],
				'state' => (int) $item['state']
			];

			if ($key === 'proxmox.api.available' || $key === 'proxmox_ve.api.available') {
				$data['api'][$hostid] = $value;
				continue;
			}

			if ($metric === '') {
				continue;
			}

			if ($this->isCluster($key)) {
				$data['cluster'][$hostid][$metric] = $value;
			}
			elseif ($this->isQemu($key)) {
				[$node, $name, $id] = $this->guestIdentity('QEMU', (string) $item['name'], $args);
				$entity_key = $hostid.'|'.$node.'|'.$id;
				$this->putMetric($data['qemu'], $entity_key, $hostid, $node, $name, $id, $metric, $value);
			}
			elseif ($this->isLxc($key)) {
				[$node, $name, $id] = $this->guestIdentity('LXC', (string) $item['name'], $args);
				$entity_key = $hostid.'|'.$node.'|'.$id;
				$this->putMetric($data['lxc'], $entity_key, $hostid, $node, $name, $id, $metric, $value);
			}
			elseif ($this->isStorage($key, $args)) {
				[$node, $name] = $this->storageIdentity((string) $item['name'], $args);
				$entity_key = $hostid.'|'.$node.'|'.$name;
				$this->putMetric($data['storages'], $entity_key, $hostid, $node, $name, $name, $metric, $value);
			}
			elseif ($this->isNode($key)) {
				$node = $this->nodeIdentity((string) $item['name'], $args);
				$entity_key = $hostid.'|'.$node;
				$this->putMetric($data['nodes'], $entity_key, $hostid, $node, $node, $node, $metric, $value);
			}
		}

		foreach (['nodes', 'storages', 'qemu', 'lxc'] as $type) {
			$data[$type] = array_values($data[$type]);
			usort($data[$type], static function(array $a, array $b): int {
				return strnatcasecmp($a['node'].' '.$a['name'], $b['node'].' '.$b['name']);
			});
		}

		return $data;
	}

	private function attachHistory(array &$data, int $hours): void {
		$targets = [];
		$max_targets = 400;

		foreach (['nodes', 'qemu', 'lxc'] as $type) {
			foreach ($data[$type] as $index => &$entity) {
				$entity['history'] = ['cpu' => [], 'memory' => [], 'network_in' => [], 'network_out' => []];
				$this->addHistoryTarget($targets, $type, $index, 'cpu', $entity, 'cpu', null, $max_targets);

				if (isset($entity['metrics']['memory_pct'])) {
					$this->addHistoryTarget($targets, $type, $index, 'memory', $entity, 'memory_pct', null, $max_targets);
				}
				elseif (isset($entity['metrics']['memory_used'])) {
					$total = (float) ($entity['metrics']['memory_total']['value'] ?? 0);
					$this->addHistoryTarget($targets, $type, $index, 'memory', $entity, 'memory_used', $total, $max_targets);
				}

				if ($type !== 'nodes') {
					$this->addHistoryTarget($targets, $type, $index, 'network_in', $entity,
						'network_in', null, $max_targets, false);
					$this->addHistoryTarget($targets, $type, $index, 'network_out', $entity,
						'network_out', null, $max_targets, false);
				}
			}
			unset($entity);
		}

		if (!$targets) {
			return;
		}

		$time_from = time() - $hours * 3600;
		$bucket_count = 144;
		$bucket_size = max(1, (int) ceil(($hours * 3600) / $bucket_count));
		$data['history_from'] = $time_from;
		$data['history_bucket_seconds'] = $bucket_size;
		$buckets = [];
		$by_value_type = [];

		foreach ($targets as $itemid => $target) {
			$by_value_type[$target['value_type']][] = $itemid;
		}

		try {
			foreach ($by_value_type as $value_type => $itemids) {
				foreach (array_chunk($itemids, 25) as $itemids_batch) {
					$rows = API::History()->get([
						'output' => ['itemid', 'clock', 'value'],
						'history' => (int) $value_type,
						'itemids' => $itemids_batch,
						'time_from' => $time_from,
						'sortfield' => 'clock',
						'sortorder' => 'ASC',
						'limit' => 50000
					]);

					foreach ($rows as $row) {
						$itemid = (string) $row['itemid'];
						if (!isset($targets[$itemid]) || !is_numeric($row['value'])) {
							continue;
						}
						$value = (float) $row['value'];
						$divisor = $targets[$itemid]['divisor'];
						if ($divisor !== null) {
							if ($divisor <= 0) {
								continue;
							}
							$value = $value / $divisor * 100;
						}
						$value = $targets[$itemid]['percent']
							? max(0, min(100, $value))
							: max(0, $value);
						$bucket = min(
							$bucket_count - 1,
							max(0, intdiv((int) $row['clock'] - $time_from, $bucket_size))
						);
						if (!isset($buckets[$itemid][$bucket])) {
							$buckets[$itemid][$bucket] = ['sum' => 0.0, 'count' => 0];
						}
						$buckets[$itemid][$bucket]['sum'] += $value;
						$buckets[$itemid][$bucket]['count']++;
					}
				}
			}
		}
		catch (\Throwable $exception) {
			return;
		}

		foreach ($targets as $itemid => $target) {
			$points = [];
			for ($bucket = 0; $bucket < $bucket_count; $bucket++) {
				$sample = $buckets[$itemid][$bucket] ?? null;
				$points[] = $sample && $sample['count']
					? round($sample['sum'] / $sample['count'], 1)
					: null;
			}
			$data[$target['type']][$target['index']]['history'][$target['series']] = $points;
		}
	}

	private function addHistoryTarget(array &$targets, string $type, int $index, string $series,
			array $entity, string $metric, ?float $divisor, int $max_targets, bool $percent = true): void {
		if (count($targets) >= $max_targets || !isset($entity['metrics'][$metric])) {
			return;
		}
		$item = $entity['metrics'][$metric];
		$itemid = (string) ($item['itemid'] ?? '');
		if ($itemid === '') {
			return;
		}
		$targets[$itemid] = [
			'type' => $type,
			'index' => $index,
			'series' => $series,
			'value_type' => (int) ($item['value_type'] ?? 0),
			'divisor' => $divisor,
			'percent' => $percent
		];
	}

	private function putMetric(array &$entities, string $entity_key, string $hostid, string $node,
			string $name, string $id, string $metric, array $value): void {
		if (!isset($entities[$entity_key])) {
			$entities[$entity_key] = [
				'hostid' => $hostid,
				'node' => $node,
				'name' => $name,
				'id' => $id,
				'metrics' => []
			];
		}
		if ($metric !== '') {
			$entities[$entity_key]['metrics'][$metric] = $value;
		}
	}

	private function keyArguments(string $key): array {
		if (!preg_match('/\[([^\]]*)\]$/', $key, $matches)) {
			return [];
		}
		return array_map(static function(string $value): string {
			return trim($value, " \t\n\r\0\x0B\"");
		}, explode(',', $matches[1]));
	}

	private function isQemu(string $key): bool {
		return strpos($key, 'proxmox.qemu.') === 0 || strpos($key, 'proxmox_ve.node.qemu.') === 0;
	}

	private function isCluster(string $key): bool {
		return strpos($key, 'proxmox.cluster.') === 0 || strpos($key, 'proxmox_ve.cluster.') === 0;
	}

	private function isLxc(string $key): bool {
		return strpos($key, 'proxmox.lxc.') === 0 || strpos($key, 'proxmox_ve.node.lxc.') === 0;
	}

	private function isStorage(string $key, array $args): bool {
		return strpos($key, 'proxmox_ve.node.storage.') === 0
			|| strpos($key, 'proxmox.node.plugintype[') === 0
			|| strpos($key, 'proxmox.node.content[') === 0
			|| ((strpos($key, 'proxmox.node.disk[') === 0 || strpos($key, 'proxmox.node.maxdisk[') === 0)
				&& count($args) > 1);
	}

	private function isNode(string $key): bool {
		return strpos($key, 'proxmox.node.') === 0 || strpos($key, 'proxmox_ve.node.') === 0;
	}

	private function nodeIdentity(string $name, array $args): string {
		if (preg_match('/Node \[([^\]]+)\]/u', $name, $matches)) {
			return $matches[1];
		}
		return $this->cleanNodeId($args[0] ?? 'Unknown');
	}

	private function storageIdentity(string $name, array $args): array {
		$node = '';
		$storage = '';
		if (preg_match('/Node \[([^\]]+)\]/u', $name, $matches)) {
			$node = $matches[1];
		}
		if (preg_match('/Storage \[([^\]]+)\]/u', $name, $matches)) {
			$storage = $matches[1];
			if (strpos($storage, '/') !== false && $node === '') {
				[$node, $storage] = array_pad(explode('/', $storage, 2), 2, '');
			}
		}
		$node = $node ?: $this->cleanNodeId($args[0] ?? 'Unknown');
		$storage = $storage ?: ($args[1] ?? 'Storage');
		return [$node, $storage];
	}

	private function guestIdentity(string $type, string $name, array $args): array {
		$node = '';
		$guest = '';
		$id = $args[count($args) - 1] ?? '';
		if (preg_match('/Node \[([^\]]+)\]/u', $name, $matches)) {
			$node = $matches[1];
		}
		if (preg_match('/'.$type.' \[([^\]]+)\]/u', $name, $matches)) {
			$guest = $matches[1];
		}
		if ($type === 'QEMU' && preg_match('/VM \[([^\/\]]+)\/(.+?) \(([^)]+)\)\]/u', $name, $matches)) {
			$node = $matches[1];
			$guest = $matches[2];
			$id = $matches[3];
		}
		elseif ($type === 'LXC' && preg_match('/LXC \[([^\/\]]+)\/(.+?)(?: \(([^)]+)\))?\]/u', $name, $matches)) {
			$node = $matches[1];
			$guest = $matches[2];
			$id = $matches[3] ?? $id;
		}
		$node = $node ?: $this->cleanNodeId($args[0] ?? 'Unknown');
		$guest = $guest ?: ($id !== '' ? $type.' '.$id : $type);
		$id = $this->cleanResourceId($id);
		return [$node, $guest, $id];
	}

	private function cleanResourceId(string $id): string {
		return strpos($id, '/') !== false ? substr($id, strrpos($id, '/') + 1) : $id;
	}

	private function cleanNodeId(string $id): string {
		return strpos($id, '/') !== false ? substr($id, strrpos($id, '/') + 1) : $id;
	}

	private function metric(string $key): string {
		$base = preg_replace('/\[.*$/', '', $key);
		$map = [
			'proxmox.api.available' => 'api',
			'proxmox_ve.api.available' => 'api',
			'proxmox.cluster.quorate' => 'quorate',
			'proxmox_ve.cluster.qemu.running' => 'qemu_running',
			'proxmox_ve.cluster.qemu.stopped' => 'qemu_stopped',
			'proxmox_ve.cluster.lxc.running' => 'lxc_running',
			'proxmox_ve.cluster.lxc.stopped' => 'lxc_stopped',
			'proxmox_ve.cluster.cpu.count' => 'cpu_count',
			'proxmox_ve.cluster.cpu.utilization' => 'cpu',
			'proxmox_ve.cluster.memory.used' => 'memory_used',
			'proxmox_ve.cluster.memory.total' => 'memory_total',
			'proxmox_ve.cluster.memory.utilization' => 'memory_pct',
			'proxmox_ve.cluster.storage.used' => 'storage_used',
			'proxmox_ve.cluster.storage.total' => 'storage_total',
			'proxmox_ve.cluster.storage.utilization' => 'storage_pct',
			'proxmox.node.online' => 'status',
			'proxmox.node.uptime' => 'uptime',
			'proxmox.node.cpu' => 'cpu',
			'proxmox.node.memused' => 'memory_used',
			'proxmox.node.memtotal' => 'memory_total',
			'proxmox.node.rootused' => 'disk_used',
			'proxmox.node.roottotal' => 'disk_total',
			'proxmox.node.swapused' => 'swap_used',
			'proxmox.node.swaptotal' => 'swap_total',
			'proxmox.node.pveversion' => 'version',
			'proxmox.node.kernelversion' => 'kernel',
			'proxmox.node.plugintype' => 'type',
			'proxmox.node.content' => 'content',
			'proxmox.node.disk' => 'used',
			'proxmox.node.maxdisk' => 'total',
			'proxmox.qemu.vmstatus' => 'status',
			'proxmox.qemu.uptime' => 'uptime',
			'proxmox.qemu.cpu' => 'cpu',
			'proxmox.qemu.mem' => 'memory_used',
			'proxmox.qemu.maxmem' => 'memory_total',
			'proxmox.qemu.netin' => 'network_in',
			'proxmox.qemu.netout' => 'network_out',
			'proxmox.qemu.diskread' => 'disk_read',
			'proxmox.qemu.diskwrite' => 'disk_write',
			'proxmox.lxc.vmstatus' => 'status',
			'proxmox.lxc.uptime' => 'uptime',
			'proxmox.lxc.cpu' => 'cpu',
			'proxmox.lxc.mem' => 'memory_used',
			'proxmox.lxc.maxmem' => 'memory_total',
			'proxmox.lxc.disk' => 'disk_used',
			'proxmox.lxc.maxdisk' => 'disk_total',
			'proxmox.lxc.netin' => 'network_in',
			'proxmox.lxc.netout' => 'network_out',
			'proxmox.lxc.diskread' => 'disk_read',
			'proxmox.lxc.diskwrite' => 'disk_write',
			'proxmox_ve.node.status' => 'status',
			'proxmox_ve.node.uptime' => 'uptime',
			'proxmox_ve.node.cpu' => 'cpu',
			'proxmox_ve.node.cpu.max' => 'cpu_count',
			'proxmox_ve.node.mem' => 'memory_used',
			'proxmox_ve.node.maxmem' => 'memory_total',
			'proxmox_ve.node.mem.utilization' => 'memory_pct',
			'proxmox_ve.node.disk' => 'disk_used',
			'proxmox_ve.node.maxdisk' => 'disk_total',
			'proxmox_ve.node.disk.utilization' => 'disk_pct',
			'proxmox_ve.node.version' => 'version',
			'proxmox_ve.node.ip' => 'ip',
			'proxmox_ve.node.subscribe' => 'subscription',
			'proxmox_ve.node.updates' => 'updates',
			'proxmox_ve.node.storage.type' => 'type',
			'proxmox_ve.node.storage.content' => 'content',
			'proxmox_ve.node.storage.used' => 'used',
			'proxmox_ve.node.storage.total' => 'total',
			'proxmox_ve.node.storage.utilization' => 'pct',
			'proxmox_ve.node.qemu.status' => 'status',
			'proxmox_ve.node.qemu.agent' => 'agent',
			'proxmox_ve.node.qemu.agent.version' => 'agent_version',
			'proxmox_ve.node.qemu.uptime' => 'uptime',
			'proxmox_ve.node.qemu.cpu.usage' => 'cpu',
			'proxmox_ve.node.qemu.cpu.count' => 'cpu_count',
			'proxmox_ve.node.qemu.memory.usage' => 'memory_used',
			'proxmox_ve.node.qemu.memory.maxmem' => 'memory_total',
			'proxmox_ve.node.qemu.memory.utilization' => 'memory_pct',
			'proxmox_ve.node.qemu.network.input' => 'network_in',
			'proxmox_ve.node.qemu.network.output' => 'network_out',
			'proxmox_ve.node.qemu.disk.read' => 'disk_read',
			'proxmox_ve.node.qemu.disk.write' => 'disk_write',
			'proxmox_ve.node.lxc.status' => 'status',
			'proxmox_ve.node.lxc.uptime' => 'uptime',
			'proxmox_ve.node.lxc.cpu' => 'cpu',
			'proxmox_ve.node.lxc.cpu.count' => 'cpu_count',
			'proxmox_ve.node.lxc.memory.usage' => 'memory_used',
			'proxmox_ve.node.lxc.memory.max' => 'memory_total',
			'proxmox_ve.node.lxc.memory.utilization' => 'memory_pct',
			'proxmox_ve.node.lxc.disk.usage' => 'disk_used',
			'proxmox_ve.node.lxc.disk.maxdisk' => 'disk_total',
			'proxmox_ve.node.lxc.disk.utilization' => 'disk_pct',
			'proxmox_ve.node.lxc.network.input' => 'network_in',
			'proxmox_ve.node.lxc.network.output' => 'network_out',
			'proxmox_ve.node.lxc.disk.read' => 'disk_read',
			'proxmox_ve.node.lxc.disk.write' => 'disk_write'
		];

		return $map[$base] ?? '';
	}
}
