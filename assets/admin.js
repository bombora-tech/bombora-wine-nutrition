/* Wine Nutrition Manager – admin UI (vanilla JS, no dependencies). */
( function () {
	'use strict';

	if ( typeof window.WNM === 'undefined' ) {
		return;
	}
	const cfg = window.WNM;
	const t = cfg.i18n;

	const el = ( tag, attrs = {}, children = [] ) => {
		const node = document.createElement( tag );
		Object.entries( attrs ).forEach( ( [ k, v ] ) => {
			if ( v === null || v === undefined || v === false ) return;
			if ( k === 'text' ) node.textContent = v;
			else if ( k === 'className' ) node.className = v;
			else if ( k.startsWith( 'on' ) ) node.addEventListener( k.slice( 2 ), v );
			else node.setAttribute( k, v === true ? '' : v );
		} );
		( Array.isArray( children ) ? children : [ children ] ).forEach( ( c ) => {
			if ( c === null || c === undefined ) return;
			node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return node;
	};

	const post = async ( action, data = {} ) => {
		const body = new FormData();
		body.append( 'action', 'wnm_' + action );
		body.append( 'nonce', cfg.nonce );
		const append = ( key, value ) => {
			if ( value !== null && typeof value === 'object' ) {
				Object.entries( value ).forEach( ( [ k, v ] ) => append( key + '[' + k + ']', v ) );
			} else {
				body.append( key, value === null || value === undefined ? '' : value );
			}
		};
		Object.entries( data ).forEach( ( [ k, v ] ) => append( k, v ) );
		const res = await fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body } );
		let json;
		try {
			json = await res.json();
		} catch ( e ) {
			throw new Error( t.error + ' ' + res.status );
		}
		if ( ! json.success ) {
			throw new Error( ( json.data && json.data.message ) || t.error );
		}
		return json.data;
	};

	const badge = ( ok, okText, badText, neutral ) =>
		el( 'span', { className: 'wnm-badge ' + ( ok ? 'is-ok' : neutral ? 'is-neutral' : 'is-bad' ), text: ok ? okText : badText } );

	// ------------------------------------------------------------ dashboard
	const table = document.getElementById( 'wnm-table' );
	if ( table ) {
		const tbody = table.querySelector( 'tbody' );
		const pager = document.getElementById( 'wnm-pager' );
		const state = { page: 1, per_page: 50, orderby: 'title', order: 'asc', search: '', category: 0, status: '', qr: '', ingredients: '' };
		let dirty = false;

		const loadStats = async () => {
			try {
				const s = await post( 'stats' );
				document.querySelectorAll( '[data-stat]' ).forEach( ( n ) => {
					const v = s[ n.dataset.stat ];
					n.textContent = v === undefined ? '–' : Number( v ).toLocaleString();
				} );
				const pending = document.getElementById( 'wnm-pending' );
				if ( s.pending_renders > 0 ) {
					pending.hidden = false;
					pending.textContent = '⏳ ~' + s.pending_renders + ' SKU(s) waiting for background re-render (Action Scheduler).';
				} else {
					pending.hidden = true;
				}
			} catch ( e ) {}
		};

		const hasText = ( col ) => ( cfg.fields.text || [] ).includes( col );
		const COLS = 6 + ( hasText( 'ingredients' ) ? 1 : 0 ) + ( hasText( 'qr_url' ) ? 1 : 0 );

		const statusCells = ( rec ) => [
			el( 'td', {}, rec ? badge( rec.nutrition_ok, t.ok, t.incomplete ) : badge( false, '', t.noRecord, true ) ),
			hasText( 'ingredients' ) ? el( 'td', {}, badge( rec && rec.ingredients_ok, t.ok, t.missing ) ) : null,
			hasText( 'qr_url' ) ? el( 'td', {}, badge( rec && rec.qr_ok, t.ok, t.missing ) ) : null,
			el( 'td', { text: rec ? rec.updated_at : '—' } ),
		].filter( Boolean );

		const input = ( name, value, label, type = 'text', extra = {} ) => {
			const id = 'wnm-f-' + Math.random().toString( 36 ).slice( 2 );
			return el( 'div', { className: 'wnm-field' + ( extra.wide ? ' is-wide' : '' ) }, [
				el( 'label', { for: id, text: label } ),
				type === 'textarea'
					? el( 'textarea', { id, name, rows: 3, oninput: () => ( dirty = true ) }, value || '' )
					: el( 'input', Object.assign( { id, name, type, value: value === null || value === undefined ? '' : value, oninput: () => ( dirty = true ) }, extra.attrs || {} ) ),
			] );
		};

		const editor = ( item, row ) => {
			const v = ( item.record && item.record.values ) || {};
			const cf = v.custom_fields || {};
			const fields = [];
			const num = { type: 'text', attrs: { inputmode: 'text', autocomplete: 'off', pattern: '\\s*(<=|>=|[<>≤≥])?\\s*[0-9]*[.,]?[0-9]*\\s*', title: t.numHint } };

			cfg.fields.standard.forEach( ( f ) => {
				if ( f.key === 'energy' ) {
					fields.push( input( 'energy_kj', v.energy_kj, t.energyKj, 'text', num ) );
					fields.push( input( 'energy_kcal', v.energy_kcal, t.energyKcal, 'text', num ) );
				} else {
					fields.push( input( f.columns[ 0 ], v[ f.columns[ 0 ] ], f.label + ' (g)', 'text', num ) );
				}
			} );
			cfg.fields.custom.forEach( ( f ) => fields.push( input( 'custom_fields[' + f.key + ']', cf[ f.key ], f.label ) ) );
			if ( hasText( 'ingredients' ) ) {
				fields.push( input( 'ingredients', v.ingredients, t.ingredients, 'textarea', { wide: true } ) );
			}
			if ( hasText( 'allergens' ) ) {
				fields.push( input( 'allergens', v.allergens, t.allergens, 'text', { wide: true } ) );
			}
			if ( hasText( 'qr_url' ) ) {
				fields.push( input( 'qr_url', v.qr_url, t.qrUrl, 'url', { wide: true } ) );
			}

			const msg = el( 'span', { className: 'wnm-msg', 'aria-live': 'polite' } );
			const form = el( 'form', { className: 'wnm-editor' }, [ el( 'div', { className: 'wnm-grid' }, fields ) ] );
			const saveBtn = el( 'button', { type: 'submit', className: 'button button-primary', text: t.save } );
			const actions = el( 'div', { className: 'wnm-editor__actions' }, [ saveBtn, msg ] );
			if ( item.record ) {
				actions.appendChild(
					el( 'button', {
						type: 'button',
						className: 'button-link wnm-delete',
						text: t.delete,
						onclick: async () => {
							if ( ! window.confirm( t.confirmDel ) ) return;
							try {
								await post( 'delete', { sku: item.sku } );
								dirty = false;
								load();
								loadStats();
							} catch ( e ) {
								msg.textContent = e.message;
								msg.className = 'wnm-msg is-error';
							}
						},
					} )
				);
			}
			form.appendChild( actions );

			form.addEventListener( 'submit', async ( ev ) => {
				ev.preventDefault();
				saveBtn.disabled = true;
				msg.className = 'wnm-msg';
				msg.textContent = t.saving;
				const data = { sku: item.sku, fields: {} };
				new FormData( form ).forEach( ( value, key ) => {
					const m = key.match( /^custom_fields\[(.+)\]$/ );
					if ( m ) {
						data.fields.custom_fields = data.fields.custom_fields || {};
						data.fields.custom_fields[ m[ 1 ] ] = value;
					} else {
						data.fields[ key ] = value;
					}
				} );
				try {
					const res = await post( 'save', data );
					item.record = res.record;
					dirty = false;
					const cells = row.querySelectorAll( 'td' );
					statusCells( item.record ).forEach( ( cell, i ) => cells[ 3 + i ].replaceWith( cell ) );
					msg.textContent = res.outcome === 'unchanged' ? t.unchanged : t.saved + ( res.rendered ? ' · ' + res.rendered + ' ' + t.rendered : '' );
					if ( res.warnings && res.warnings.length ) {
						msg.textContent += ' ⚠ ' + res.warnings.join( ' ' );
						msg.className = 'wnm-msg is-warning';
					} else {
						msg.className = 'wnm-msg is-success';
					}
					loadStats();
				} catch ( e ) {
					msg.textContent = e.message;
					msg.className = 'wnm-msg is-error';
				} finally {
					saveBtn.disabled = false;
				}
			} );
			return form;
		};

		const render = ( data ) => {
			tbody.textContent = '';
			if ( ! data.rows.length ) {
				tbody.appendChild( el( 'tr', {}, el( 'td', { colspan: COLS, text: t.noResults } ) ) );
			}
			data.rows.forEach( ( item ) => {
				const row = el( 'tr', { 'data-sku': item.sku } );
				let editRow = null;
				const btn = el( 'button', {
					type: 'button',
					className: 'button button-small',
					'aria-expanded': 'false',
					text: t.edit,
					onclick: () => {
						if ( editRow ) {
							if ( dirty && ! window.confirm( t.unsaved ) ) return;
							dirty = false;
							editRow.remove();
							editRow = null;
							btn.textContent = t.edit;
							btn.setAttribute( 'aria-expanded', 'false' );
							return;
						}
						editRow = el( 'tr', { className: 'wnm-edit-row' }, el( 'td', { colspan: COLS }, editor( item, row ) ) );
						row.after( editRow );
						btn.textContent = t.close;
						btn.setAttribute( 'aria-expanded', 'true' );
						const first = editRow.querySelector( 'input,textarea' );
						if ( first ) first.focus();
					},
				} );
				row.append(
					el( 'td', {}, el( 'code', { text: item.sku } ) ),
					el( 'td', {}, [ el( 'a', { href: item.edit_url, text: item.title } ), item.status !== 'publish' ? el( 'span', { className: 'wnm-post-status', text: ' (' + item.status + ')' } ) : null ] ),
					el( 'td', { className: 'wnm-cats', text: item.categories } ),
					...statusCells( item.record ),
					el( 'td', {}, btn )
				);
				tbody.appendChild( row );
			} );

			pager.textContent = '';
			const info = el( 'span', { text: data.total + ' ' + t.items + ' · ' + t.page + ' ' + data.page + ' ' + t.of + ' ' + Math.max( 1, data.pages ) } );
			const prev = el( 'button', { type: 'button', className: 'button', text: '‹', 'aria-label': 'Previous page', disabled: data.page <= 1, onclick: () => go( data.page - 1 ) } );
			const next = el( 'button', { type: 'button', className: 'button', text: '›', 'aria-label': 'Next page', disabled: data.page >= data.pages, onclick: () => go( data.page + 1 ) } );
			pager.append( prev, info, next );
		};

		let reqId = 0;
		const load = async () => {
			const id = ++reqId;
			table.classList.add( 'is-loading' );
			try {
				const data = await post( 'list', state );
				if ( id === reqId ) render( data );
			} catch ( e ) {
				tbody.textContent = '';
				tbody.appendChild( el( 'tr', {}, el( 'td', { colspan: COLS, text: e.message } ) ) );
			} finally {
				if ( id === reqId ) table.classList.remove( 'is-loading' );
			}
		};
		const go = ( page ) => {
			if ( dirty && ! window.confirm( t.unsaved ) ) return;
			dirty = false;
			state.page = page;
			load();
		};

		let timer;
		document.getElementById( 'wnm-search' ).addEventListener( 'input', ( e ) => {
			clearTimeout( timer );
			timer = setTimeout( () => {
				state.search = e.target.value.trim();
				go( 1 );
			}, 300 );
		} );
		[ 'category', 'status', 'qr', 'ingredients', 'perpage' ].forEach( ( key ) => {
			// Filters for disabled fields (QR, Ingredients) are not rendered.
			const node = document.getElementById( 'wnm-' + key );
			if ( ! node ) {
				return;
			}
			node.addEventListener( 'change', ( e ) => {
				state[ key === 'perpage' ? 'per_page' : key ] = e.target.value;
				go( 1 );
			} );
		} );
		table.querySelectorAll( '.wnm-sortable' ).forEach( ( th ) => {
			th.tabIndex = 0;
			const sort = () => {
				const by = th.dataset.orderby;
				state.order = state.orderby === by && state.order === 'asc' ? 'desc' : 'asc';
				state.orderby = by;
				table.querySelectorAll( '.wnm-sortable' ).forEach( ( h ) => h.removeAttribute( 'aria-sort' ) );
				th.setAttribute( 'aria-sort', state.order === 'asc' ? 'ascending' : 'descending' );
				go( 1 );
			};
			th.addEventListener( 'click', sort );
			th.addEventListener( 'keydown', ( e ) => ( e.key === 'Enter' || e.key === ' ' ) && ( e.preventDefault(), sort() ) );
		} );

		window.addEventListener( 'beforeunload', ( e ) => {
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );

		load();
		loadStats();
	}

	// --------------------------------------------------------------- import
	const imp = document.getElementById( 'wnm-import' );
	if ( imp ) {
		const run = document.getElementById( 'wnm-import-run' );
		const bar = imp.querySelector( '.wnm-progress' );
		const fill = imp.querySelector( '.wnm-progress__bar' );
		const status = imp.querySelector( '.wnm-import-status' );
		const report = imp.querySelector( '.wnm-import-report' );
		const logBase = document.getElementById( 'wnm-log-url' ).innerHTML.replace( /&amp;/g, '&' );

		run.addEventListener( 'click', async () => {
			run.disabled = true;
			imp.querySelector( '.wnm-cancel-form' ).hidden = true;
			imp.querySelectorAll( '.wnm-options input' ).forEach( ( i ) => ( i.disabled = true ) );
			bar.hidden = false;
			status.textContent = t.importing;
			const opts = {
				token: imp.dataset.token,
				store_unmatched: document.getElementById( 'wnm-store-unmatched' ).checked ? 1 : '',
				empty_clears: document.getElementById( 'wnm-empty-clears' ).checked ? 1 : '',
			};
			try {
				let res;
				do {
					res = await post( 'import_batch', opts );
					const pct = res.total ? Math.min( 100, Math.round( ( res.processed / res.total ) * 100 ) ) : 100;
					fill.style.width = pct + '%';
					status.textContent = t.importing + ' ' + res.processed + ' / ' + res.total;
				} while ( ! res.done );

				const r = res.report;
				status.textContent = t.importDone;
				report.hidden = false;
				report.textContent = '';
				const rows = [
					[ 'Created', r.created ],
					[ 'Updated', r.updated ],
					[ 'Unchanged', r.unchanged ],
					[ 'Skipped', r.skipped ],
					[ 'Errors', r.errors ],
					[ 'Queued for re-render', r.rendered ],
				];
				report.appendChild(
					el( 'table', { className: 'widefat wnm-preview' }, el( 'tbody', {}, rows.map( ( [ k, v ] ) => el( 'tr', {}, [ el( 'th', { text: k } ), el( 'td', { text: String( v ) } ) ] ) ) ) )
				);
				report.appendChild( el( 'p', {}, el( 'a', { className: 'button', href: logBase + '&run_id=' + encodeURIComponent( res.run_id ), text: 'Download import log (CSV)' } ) ) );
				run.hidden = true;
			} catch ( e ) {
				status.textContent = t.error + ': ' + e.message;
				run.disabled = false;
			}
		} );
	}

	// ------------------------------------------------------------- settings
	const add = document.getElementById( 'wnm-add-custom' );
	if ( add ) {
		add.addEventListener( 'click', () => {
			const i = Number( add.dataset.next );
			add.dataset.next = String( i + 1 );
			const n = ( f ) => 'custom[' + i + '][' + f + ']';
			document.getElementById( 'wnm-custom-rows' ).appendChild(
				el( 'tr', {}, [
					el( 'td', {}, el( 'input', { type: 'checkbox', name: n( 'enabled' ), value: '1', checked: true } ) ),
					el( 'td', {}, el( 'input', { type: 'text', name: n( 'label' ), required: true, placeholder: 'Fiber' } ) ),
					el( 'td', {}, el( 'input', { type: 'text', name: n( 'key' ), placeholder: 'auto', pattern: '[a-z0-9_]*', className: 'small-text' } ) ),
					el( 'td', {}, '' ),
				] )
			);
		} );
	}
} )();
