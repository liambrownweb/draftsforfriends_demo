jQuery( function() {
	jQuery( 'form.draftsforfriends-extend' ).hide();
	jQuery( 'a.draftsforfriends-extend' ).show();
	jQuery( 'a.draftsforfriends-extend-cancel' ).show();
	jQuery( 'a.draftsforfriends-extend-cancel' ).css( 'display', 'inline' );
} );
/**
 * Front-end draftsforfriends object.
 *
 * UI functionality for key form operations: 
 * * toggle_extend() toggles the time extension form for a draft.
 * * cancel_extend() cancels an extension attempt and hides the form.
 * * copy_draft_link() copies the link to the clipboard so the user doesn't 
 *   have to carefully select the link in the page.
 */ 
window.draftsforfriends = {
	toggle_extend: function( key ) {
		jQuery( '#draftsforfriends-extend-form-'+key ).show();
		jQuery( '#draftsforfriends-extend-link-'+key ).hide();
		jQuery( '#draftsforfriends-extend-form-'+key+' input[name="expires"]' ).focus();
	},
	cancel_extend: function( key ) {
		jQuery( '#draftsforfriends-extend-form-'+key ).hide();
		jQuery( '#draftsforfriends-extend-link-'+key ).show();
	},
	copy_draft_link: function( key ) {
		if ( !navigator.clipboard ) {
			var link_element = document.createElement('textArea');
			link_element.value = key;
			document.body.appendChild( link_element );
			link_element.focus();
			link_element.select();
			document.execCommand( 'copy' );
			document.body.removeChild( link_element );
		} else {
			navigator.clipboard.writeText( key );
		}
	}
};
