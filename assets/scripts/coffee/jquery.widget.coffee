$ = @jQuery
settings = @lowtone_terms_filter

$ ->
	$widgets = $ '.widget_lowtone_terms_filter, .widget_lowtone_terms_filters'

	$widgets.find('ul').each ->
		$list = $ this

		$items = $list.find 'li'

		$hidden = $items.slice parseInt(settings.visible_items) + 1

		return if $hidden.length < 1

		show_text = settings.locales.show_text.replace '{num_items}', $items.length
		hide_text = settings.locales.hide_text.replace '{num_items}', $hidden.length
		
		$toggle = $('<a class="toggle">').insertAfter $items.parent()

		toggle = ->
			$hidden.toggle()

			if $hidden.is ':hidden' 
				$toggle
					.html(show_text)
					.addClass('expand')
					.removeClass('collapse')
			else 
				$toggle
					.html(hide_text)
					.addClass('collapse')
					.removeClass('expand')

		toggle()

		$toggle.click toggle