( function( $ ) {
	DSPublicPostPreview = {
		initialize : function() {
			var t = this;

			t.checkbox = $( '#public-post-preview' );
			t.link     = $( '#public-post-preview-link' );
			t.nonce    = $( '#public_post_preview_wpnonce' );
			t.status   = $( '#public-post-preview-ajax' );

			if ( ! t.checkbox.prop( 'checked' ) )
				t.link.hide();

			t.checkbox.bind( 'change', function() {
				t.change();
			} );
		},

		change : function() {
			var t = this,
				checked = t.checkbox.prop( 'checked' ) ? 1 : 0;

			t.link.toggle();

			t.checkbox.prop( 'disabled', 'disabled' );

			t.request(
				{
					_ajax_nonce : t.nonce.val(),
					checked : checked,
					post_ID : $( '#post_ID' ).val()
				},
				function( data ) {
					if ( data ) {
						if ( checked ) {
							t.status.text( DSPublicPostPreviewL10n.enabled );
							t._pulsate( t.status, 'green' );
						} else {
							t.status.text( DSPublicPostPreviewL10n.disabled );
							t._pulsate( t.status, 'red' );
						}
					}
					t.checkbox.prop('disabled', '');
				}
			);
		},

		request : function( data, callback ) {
			$.ajax( {
				type: 'POST',
				url: ajaxurl,
				data: $.extend(
					data,
					{ action: 'public-post-preview' }
				),
				success : callback
			} );
		},

		_pulsate : function( e, color ) {
			e.css( 'color', color )
				.animate( {opacity: 0 }, 600, 'linear' )
				.animate( {opacity: 1 }, 600, 'linear' )
				.animate( {opacity: 0 }, 600, 'linear' )
				.animate( {opacity: 1 }, 600, 'linear' )
				.animate( {opacity: 0 }, 600, 'linear', function() {
					e.text('');
				} );
		}
	};

	$( function() {
		DSPublicPostPreview.initialize();
	} );


} )( jQuery );
