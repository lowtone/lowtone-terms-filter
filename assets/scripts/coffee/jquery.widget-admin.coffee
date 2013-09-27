$ = @jQuery
settings = @lowtone_terms_filter_admin
wp_widgets = @wpWidgets

$ ->
	$selects = $ 'select.lowtone_terms_filter.post_type'
	
	$selects.each ->
		$post_type_select = $ this

		$taxonomy_select = $post_type_select
			.closest('form')
			.find 'select.lowtone_terms_filter.taxonomy'

		update_taxonomies = ->
			data = 
				action: "lowtone_terms_filter_taxonomies"
				post_type: $post_type_select.val()

			success = (response) ->
				$taxonomy_select.empty()
				
				return if 200 != response.meta.code

				$.each response.data.taxonomies, (key, val) ->
					$taxonomy_select.append $('<option>').attr('value', key).text(val)

			$.getJSON settings.ajaxurl, data, success

		$post_type_select.change update_taxonomies