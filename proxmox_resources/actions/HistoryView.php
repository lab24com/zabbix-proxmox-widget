<?php

namespace Modules\ProxmoxResources\Actions;

use API;
use CController;
use CControllerResponseData;

class HistoryView extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'itemid' => 'required|db items.itemid',
			'secondary_itemid' => 'db items.itemid',
			'hours' => 'in 1,3,6,12'
		];
		$ret = $this->validateInput($fields);
		if (!$ret) {
			$this->respondJson(['error' => 'Solicitud histórica no válida.']);
		}
		return $ret;
	}

	protected function checkPermissions(): bool {
		return true;
	}

	protected function doAction(): void {
		$requested = [(string) $this->getInput('itemid')];
		$secondary_itemid = (string) $this->getInput('secondary_itemid', '');
		if ($secondary_itemid !== '') {
			$requested[] = $secondary_itemid;
		}
		$requested = array_values(array_unique($requested));
		$hours = (int) $this->getInput('hours', 12);
		if (!in_array($hours, [1, 3, 6, 12], true)) {
			$hours = 12;
		}

		$items = API::Item()->get([
			'output' => ['itemid', 'value_type'],
			'itemids' => $requested,
			'webitems' => true,
			'preservekeys' => true
		]);
		$allowed = array_intersect($requested, array_map('strval', array_keys($items)));
		if (!$allowed || !in_array($requested[0], $allowed, true)) {
			$this->respondJson(['error' => 'El ítem no existe o no está autorizado.']);
			return;
		}

		$time_from = time() - $hours * 3600;
		$bucket_count = 144;
		$bucket_size = max(1, (int) ceil(($hours * 3600) / $bucket_count));
		$buckets = [];
		$by_type = [];
		foreach ($allowed as $itemid) {
			$by_type[(int) $items[$itemid]['value_type']][] = $itemid;
		}

		try {
			foreach ($by_type as $value_type => $itemids) {
				$rows = API::History()->get([
					'output' => ['itemid', 'clock', 'value'],
					'history' => $value_type,
					'itemids' => $itemids,
					'time_from' => $time_from,
					'sortfield' => 'clock',
					'sortorder' => 'ASC',
					'limit' => 5000
				]);
				foreach ($rows as $row) {
					if (!is_numeric($row['value'])) {
						continue;
					}
					$itemid = (string) $row['itemid'];
					$bucket = min($bucket_count - 1,
						max(0, intdiv((int) $row['clock'] - $time_from, $bucket_size)));
					if (!isset($buckets[$itemid][$bucket])) {
						$buckets[$itemid][$bucket] = ['sum' => 0.0, 'count' => 0];
					}
					$buckets[$itemid][$bucket]['sum'] += (float) $row['value'];
					$buckets[$itemid][$bucket]['count']++;
				}
			}
		}
		catch (\Throwable $exception) {
			$this->respondJson(['error' => 'No fue posible consultar el histórico.']);
			return;
		}

		$series = [];
		foreach ($requested as $itemid) {
			$series[$itemid] = [];
			for ($bucket = 0; $bucket < $bucket_count; $bucket++) {
				$sample = $buckets[$itemid][$bucket] ?? null;
				$series[$itemid][] = $sample && $sample['count']
					? round($sample['sum'] / $sample['count'], 2)
					: null;
			}
		}

		$this->respondJson([
			'from' => $time_from,
			'bucket' => $bucket_size,
			'series' => $series
		]);
	}
	private function respondJson(array $payload): void {
		$this->setResponse(
			(new CControllerResponseData([
				'main_block' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
			]))->disableView()
		);
	}
}
