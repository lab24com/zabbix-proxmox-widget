<?php

/**
 * Proxmox Resources widget configuration view.
 *
 * @var CView $this
 * @var array $data
 */

(new CWidgetFormView($data))
	->addField(
		new CWidgetFieldMultiSelectHostView($data['fields']['hostids'])
	)
	->addField(
		new CWidgetFieldSelectView($data['fields']['view_mode'])
	)
	->addField(
		new CWidgetFieldSelectView($data['fields']['history_hours'])
	)
	->addField(
		new CWidgetFieldSelectView($data['fields']['visible_vm_rows'])
	)
	->addField(
		new CWidgetFieldSelectView($data['fields']['vm_page_size'])
	)
	->addField(
		new CWidgetFieldSelectView($data['fields']['vm_default_filter'])
	)
	->show();
