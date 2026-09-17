<?php

namespace Modules\ProxmoxResources\Includes;

use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\CWidgetFieldMultiSelectHost;
use Zabbix\Widgets\Fields\CWidgetFieldSelect;

class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
			->addField(
				(new CWidgetFieldMultiSelectHost('hostids', _('Proxmox hosts')))
					->setMultiple(true)
			)
			->addField(
				(new CWidgetFieldSelect('view_mode', _('Modo de visualización'), [
					2 => _('Solo infraestructura'),
					1 => _('Solo máquinas virtuales QEMU'),
					0 => _('Infraestructura + máquinas virtuales')
				]))->setDefault(0)
			)
			->addField(
				(new CWidgetFieldSelect('history_hours', _('Periodo inicial de gráfica'), [
					1 => _('Última hora'),
					3 => _('Últimas 3 horas'),
					6 => _('Últimas 6 horas'),
					12 => _('Últimas 12 horas')
				]))->setDefault(1)
			)
			->addField(
				(new CWidgetFieldSelect('visible_vm_rows', _('Filas de VM visibles'), [
					8 => _('8 filas'),
					12 => _('12 filas'),
					16 => _('16 filas'),
					20 => _('20 filas')
				]))->setDefault(12)
			)
			->addField(
				(new CWidgetFieldSelect('vm_page_size', _('VM por página'), [
					25 => _('25 VM'),
					50 => _('50 VM'),
					100 => _('100 VM')
				]))->setDefault(50)
			)
			->addField(
				(new CWidgetFieldSelect('vm_default_filter', _('Filtro inicial de VM'), [
					1 => _('En línea'),
					0 => _('Todas'),
					2 => _('Detenidas'),
					3 => _('Críticas'),
					4 => _('Advertencias'),
					5 => _('Sin datos')
				]))->setDefault(1)
			);
	}
}
