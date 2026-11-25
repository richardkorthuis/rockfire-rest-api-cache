/**
 * Rockfire REST API Cache – admin behaviour. Vanilla JavaScript, no dependencies.
 *
 * - Confirmation: a link, button or form with `data-rfrac-confirm="Message"` asks for confirmation
 *   before it follows the link or submits.
 * - Field toggles: an element with `data-rfrac-show-if="field_name:value"` (several values separated
 *   by `|`) is shown only while the radio, checkbox or select named `field_name` in the same form
 *   (or document) has one of these values, e.g. the parameter fields of the route editor:
 *   `data-rfrac-show-if="param_mode:registered"`. A checkbox list is named `field[]` and is matched
 *   with that name, e.g. `data-rfrac-show-if="methods[]:POST|PUT|PATCH"`.
 */
( function () {
	'use strict';

	function confirmed( element ) {
		var message = element.getAttribute( 'data-rfrac-confirm' );
		return ! message || window.confirm( message );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		var element = target && target.closest ? target.closest( 'a[data-rfrac-confirm], button[data-rfrac-confirm], input[data-rfrac-confirm]' ) : null;
		if ( element && ! confirmed( element ) ) {
			event.preventDefault();
			event.stopPropagation();
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( form && form.hasAttribute && form.hasAttribute( 'data-rfrac-confirm' ) && ! confirmed( form ) ) {
			event.preventDefault();
		}
	} );

	function currentValues( scope, name ) {
		var values = [];
		var fields = scope.querySelectorAll( '[name="' + name + '"]' );
		Array.prototype.forEach.call( fields, function ( field ) {
			if ( 'radio' === field.type || 'checkbox' === field.type ) {
				if ( field.checked ) {
					values.push( field.value );
				}
			} else {
				values.push( field.value );
			}
		} );
		return values;
	}

	function updateToggles() {
		var elements = document.querySelectorAll( '[data-rfrac-show-if]' );
		Array.prototype.forEach.call( elements, function ( element ) {
			var rule = element.getAttribute( 'data-rfrac-show-if' ) || '';
			var separator = rule.indexOf( ':' );
			if ( separator < 1 ) {
				return;
			}
			var name = rule.slice( 0, separator );
			var wanted = rule.slice( separator + 1 ).split( '|' );
			var scope = element.closest( 'form' ) || document;
			var show = currentValues( scope, name ).some( function ( value ) {
				return -1 !== wanted.indexOf( value );
			} );
			element.hidden = ! show;
		} );
	}

	document.addEventListener( 'change', updateToggles );
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', updateToggles );
	} else {
		updateToggles();
	}
}() );
