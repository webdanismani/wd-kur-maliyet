/* WD Kur Fiyat – yönetim paneli */
(function ($) {
	'use strict';
	$(function () {
		$(document).on('submit', 'form[data-confirm]', function (e) {
			if (!window.confirm($(this).data('confirm'))) { e.preventDefault(); }
		});

		$('.wdkf-check-all').on('change', function () {
			$(this).closest('table').find('tbody input[type=checkbox]').prop('checked', this.checked);
		});

		// Kategori kuralları: satır ekle / sil.
		var $rules = $('.wdkf-rules tbody');
		var next = $rules.find('tr.wdkf-rule').length;
		$('.wdkf-rule-add').on('click', function () {
			var $tpl = $rules.find('tr.wdkf-rule-tpl');
			var $row = $tpl.clone().removeClass('wdkf-rule-tpl').addClass('wdkf-rule').prop('hidden', false);
			$row.find('[name]').each(function () {
				this.name = this.name.replace('__i__', String(next));
				this.disabled = false;
			});
			next++;
			$row.insertBefore($tpl);
			$row.find('select').first().trigger('focus');
		});
		$rules.on('click', '.wdkf-rule-del', function () {
			$(this).closest('tr').remove();
		});
	});
})(jQuery);
