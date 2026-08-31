( function () {
	'use strict';

	var tabList = document.querySelector( '[data-healthlens-tabs]' );

	if ( ! tabList ) {
		return;
	}

	var tabs = Array.prototype.slice.call( tabList.querySelectorAll( '[data-healthlens-tab]' ) );
	var panels = Array.prototype.slice.call( document.querySelectorAll( '[data-healthlens-panel]' ) );

	if ( ! tabs.length || ! panels.length ) {
		return;
	}

	tabList.setAttribute( 'role', 'tablist' );

	tabs.forEach( function ( tab ) {
		tab.setAttribute( 'role', 'tab' );
		tab.setAttribute( 'aria-controls', tab.getAttribute( 'href' ).slice( 1 ) );
	} );

	panels.forEach( function ( panel ) {
		panel.setAttribute( 'role', 'tabpanel' );
		panel.setAttribute( 'tabindex', '0' );
		panel.setAttribute( 'aria-labelledby', 'healthlens-tab-' + panel.dataset.healthlensPanel );
	} );

	/**
	 * Show one dashboard view and preserve a stable URL fragment.
	 *
	 * @param {HTMLElement} selectedTab Tab to activate.
	 * @param {boolean} updateHistory Whether to update the URL fragment.
	 * @return {void}
	 */
	function activateTab( selectedTab, updateHistory ) {
		var panelId = selectedTab.getAttribute( 'aria-controls' );

		tabs.forEach( function ( tab ) {
			var selected = tab === selectedTab;
			tab.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
			tab.setAttribute( 'tabindex', selected ? '0' : '-1' );
		} );

		panels.forEach( function ( panel ) {
			panel.hidden = panel.id !== panelId;
		} );

		if ( updateHistory && window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', '#' + panelId );
		}
	}

	/**
	 * Return the tab controlled by a supported dashboard fragment.
	 *
	 * @return {HTMLElement|null} Matching tab or null.
	 */
	function tabFromHash() {
		var panelId = window.location.hash.slice( 1 );

		return tabs.find( function ( tab ) {
			return tab.getAttribute( 'aria-controls' ) === panelId;
		} ) || null;
	}

	tabs.forEach( function ( tab, index ) {
		tab.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			activateTab( tab, true );
		} );

		tab.addEventListener( 'keydown', function ( event ) {
			var targetIndex = index;

			if ( 'ArrowLeft' === event.key || 'ArrowUp' === event.key ) {
				targetIndex = ( index - 1 + tabs.length ) % tabs.length;
			} else if ( 'ArrowRight' === event.key || 'ArrowDown' === event.key ) {
				targetIndex = ( index + 1 ) % tabs.length;
			} else if ( 'Home' === event.key ) {
				targetIndex = 0;
			} else if ( 'End' === event.key ) {
				targetIndex = tabs.length - 1;
			} else {
				return;
			}

			event.preventDefault();
			tabs[ targetIndex ].focus();
			activateTab( tabs[ targetIndex ], true );
		} );
	} );

	window.addEventListener( 'hashchange', function () {
		var hashTab = tabFromHash();

		if ( hashTab ) {
			activateTab( hashTab, false );
		}
	} );

	activateTab( tabFromHash() || tabs[ 0 ], false );
}() );
