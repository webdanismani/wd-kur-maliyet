/* WD Kur Fiyat – ürün düzenleme ekranı canlı önizleme */
(function ($) {
	'use strict';
	var C = window.wdkfProduct || { rates: {}, vat: 0, decimals: 2, format: '%1$s%2$s', symbol: '₺', decSep: ',', thouSep: '.' };

	function num(v) {
		if (v === undefined || v === null) { return NaN; }
		v = String(v).trim();
		if (v === '') { return NaN; }
		// "1.234,56" ve "1234.56" biçimlerini kabul et.
		if (v.indexOf(',') > -1) { v = v.replace(/\./g, '').replace(',', '.'); }
		return parseFloat(v);
	}
	function cc(x) { return Math.ceil(Math.round(x * 1e6) / 1e6); }
	function roundPrice(p, mode) {
		p = Math.round(p * 1e4) / 1e4;
		switch (String(mode)) {
			case 'int': return cc(p);
			case 'x9': return cc((p + 1) / 10) * 10 - 1;
			case 'x90': return cc((p + 10) / 100) * 100 - 10;
			case 'x99': return cc((p + 1) / 100) * 100 - 1;
			case 'd99': return Math.round((cc(p + 0.01) - 0.01) * 100) / 100;
			case '5': case '10': case '50': case '100':
				var n = parseInt(mode, 10); return cc(p / n) * n;
			default:
				var f = Math.pow(10, C.decimals); return Math.round(p * f) / f;
		}
	}
	function money(n) {
		var parts = Math.abs(n).toFixed(C.decimals).split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, C.thouSep);
		var s = (n < 0 ? '-' : '') + parts.join(C.decSep);
		return C.format.replace('%1$s', C.symbol).replace('%2$s', s).replace(/&nbsp;/g, ' ');
	}

	function update($box) {
		var on = $box.find('.wdkf-enabled').is(':checked');
		$box.find('.wdkf-fields').prop('hidden', !on);
		var $reg = $box.closest('.woocommerce_variation, #general_product_data').find('input[name^="_regular_price"], input[name^="variable_regular_price"]').first();
		$reg.prop('readonly', on).toggleClass('wdkf-locked', on);

		var mode = $box.find('.wdkf-mode').val();
		$box.find('.wdkf-cost-only').toggle(mode === 'cost');
		$box.find('.wdkf-amount-label').text(mode === 'fx' ? 'Döviz satış fiyatı' : 'Maliyet');
		if (!on) { return; }

		var amount = num($box.find('.wdkf-amount').val());
		var cur = $box.find('.wdkf-currency').val();
		var rate = C.rates[cur];
		var $price = $box.find('.wdkf-preview__price');
		var $detail = $box.find('.wdkf-preview__detail');
		if (!(amount > 0)) { $price.text('—'); $detail.text('Maliyet ve para birimi girin.'); return; }
		if (!(rate > 0)) { $price.text('—'); $detail.text(cur + ' kuru henüz alınmadı. Ayarlar\'dan para birimini etkinleştirip kurları güncelleyin.'); return; }

		var margin = num($box.find('.wdkf-margin').val());
		if (isNaN(margin)) { margin = parseFloat($box.data('margin')) || 0; }
		var extra = num($box.find('.wdkf-extra').val());
		if (isNaN(extra)) { extra = parseFloat($box.data('extra')) || 0; }
		var rounding = $box.find('.wdkf-rounding').val() || $box.data('rounding');
		var vat = parseFloat(C.vat) || 0;

		var base = amount * rate, raw;
		if (mode === 'fx') { raw = base * (1 + vat / 100); }
		else { raw = (base + extra) * (1 + margin / 100) * (1 + vat / 100); }
		var price = roundPrice(raw, rounding);

		var d = amount.toLocaleString('tr-TR') + ' ' + cur + ' × ' + rate.toLocaleString('tr-TR', { maximumFractionDigits: 4 });
		if (mode === 'cost') {
			if (extra) { d += ' + ' + extra.toLocaleString('tr-TR') + ' ek'; }
			d += ' +%' + margin.toLocaleString('tr-TR') + ' marj';
		}
		if (vat) { d += ' +%' + vat.toLocaleString('tr-TR') + ' KDV'; }
		if (mode === 'cost') { d += ' · kâr ≈ ' + money(price / (1 + vat / 100) - base - extra); }

		$price.text(money(price));
		$detail.text(d);
		$reg.val(String(price.toFixed(C.decimals)).replace('.', C.decSep));
	}

	$(document).on('change input', '.wdkf-box input, .wdkf-box select', function () {
		update($(this).closest('.wdkf-box'));
	});
	$(document).on('woocommerce_variations_loaded woocommerce_variations_added', function () {
		$('.wdkf-box').each(function () { update($(this)); });
	});
	$(function () {
		$('.wdkf-box').each(function () { update($(this)); });
	});
})(jQuery);
