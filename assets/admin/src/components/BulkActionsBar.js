/**
 * Shared controls for actions on selected list rows.
 *
 * The server returns results for each row, so partial failures stay visible.
 */

/**
 * External dependencies
 */
import {
	useEffect,
	useId,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

/**
 * Tracks selected rows on this page.
 *
 * @param {Object}        options          Selection options.
 * @param {Array<number>} options.ids      Page row ids.
 * @param {string}        options.resetKey Resets selection when visible rows change.
 * @return {Object} The selection state and handlers.
 */
export function useRowSelection( { ids, resetKey } ) {
	const [ selected, setSelected ] = useState( [] );

	useEffect( () => {
		setSelected( ( previous ) =>
			previous.length === 0 ? previous : []
		);
	}, [ resetKey ] );

	const onPage = Array.isArray( ids ) ? ids : [];

	// Keep only selected rows still on the current page.
	const live = selected.filter( ( id ) => onPage.includes( id ) );

	/**
	 * Whether one row is ticked.
	 *
	 * @param {number} id The row id.
	 * @return {boolean} True when it is part of the selection.
	 */
	function isSelected( id ) {
		return live.includes( id );
	}

	const allSelected = onPage.length > 0 && onPage.every( isSelected );

	return {
		selected: live,
		count: live.length,
		isSelected,
		allSelected,

		// Part of the page, which is neither of the checkbox's two states.
		someSelected: ! allSelected && live.length > 0,

		/**
		 * Tick or untick one row.
		 *
		 * @param {number}  id      The row id.
		 * @param {boolean} checked The state the checkbox moved to.
		 * @return {void}
		 */
		toggle( id, checked ) {
			setSelected( ( previous ) => {
				if ( ! checked ) {
					return previous.filter( ( entry ) => entry !== id );
				}

				return previous.includes( id )
					? previous
					: previous.concat( id );
			} );
		},

		/**
		 * Tick or untick every row on the page, leaving anything off it alone.
		 *
		 * @param {boolean} checked The state the header checkbox moved to.
		 * @return {void}
		 */
		toggleAll( checked ) {
			setSelected( ( previous ) => {
				if ( ! checked ) {
					return previous.filter(
						( entry ) => ! onPage.includes( entry )
					);
				}

				return previous.concat(
					onPage.filter( ( id ) => ! previous.includes( id ) )
				);
			} );
		},

		/**
		 * Drop the whole selection.
		 *
		 * @return {void}
		 */
		clear() {
			setSelected( [] );
		},

		/**
		 * Untick rows completed by the batch. Keep later selection changes.
		 *
		 * @param {Array<number>} done The ids that succeeded.
		 * @return {void}
		 */
		drop( done ) {
			setSelected( ( previous ) =>
				previous.filter( ( entry ) => ! done.includes( entry ) )
			);
		},
	};
}

/**
 * The select-all cell at the head of a table's checkbox column.
 *
 * @param {Object} props           Component props.
 * @param {Object} props.selection The binding from {@link useRowSelection}.
 * @return {Element} The header cell.
 */
export function SelectAllHeader( { selection } ) {
	const box = useRef( null );

	// A DOM property, not an attribute: nothing in JSX can set it.
	useEffect( () => {
		if ( box.current ) {
			box.current.indeterminate = selection.someSelected;
		}
	} );

	return (
		<td className="manage-column column-cb check-column">
			<input
				ref={ box }
				type="checkbox"
				aria-label={ __( 'Select all rows', 'tiermint' ) }
				checked={ selection.allSelected }
				onChange={ ( event ) =>
					selection.toggleAll( event.target.checked )
				}
			/>
		</td>
	);
}

/**
 * One row's checkbox cell.
 *
 * @param {Object} props           Component props.
 * @param {Object} props.selection The binding from {@link useRowSelection}.
 * @param {number} props.id        The row id.
 * @param {string} props.label     What the row is called, so the checkboxes down
 *                                 the column are told apart by name.
 * @return {Element} The row cell.
 */
export function SelectRowCell( { selection, id, label } ) {
	return (
		<th scope="row" className="check-column">
			<input
				type="checkbox"
				aria-label={ sprintf(
					/* translators: %s: what the row is called, for example a product name or a discount code. */
					__( 'Select %s', 'tiermint' ),
					label
				) }
				checked={ selection.isSelected( id ) }
				onChange={ ( event ) =>
					selection.toggle( id, event.target.checked )
				}
			/>
		</th>
	);
}

/**
 * How many rows are ticked, as a sentence.
 *
 * @param {number} count The selected row count.
 * @return {string} The count line.
 */
function countText( count ) {
	return sprintf(
		/* translators: %d: how many rows the merchant has ticked. */
		_n( '%d selected', '%d selected', count, 'tiermint' ),
		count
	);
}

/**
 * A row the screen can no longer name, printed as the id the failure carried.
 *
 * The row can disappear before its failure notice renders.
 *
 * @param {number} id The row id.
 * @return {string} The id in the lists' `#id` form.
 */
function missingRow( id ) {
	return sprintf(
		/* translators: %d: a numeric record id. */
		__( '#%d', 'tiermint' ),
		id
	);
}

/**
 * The bar above a list table: pick an action, press Apply.
 *
 * @param {Object}   props           Component props.
 * @param {string}   props.label     The group's accessible name, naming the list.
 * @param {Array}    props.actions   Available actions and optional confirmation text.
 * @param {number}   props.count     How many rows are ticked.
 * @param {boolean}  [props.pending] Whether a batch is still out.
 * @param {Function} props.onApply   Called with the chosen action.
 * @return {Element} The bar.
 */
export default function BulkActionsBar( {
	label,
	actions,
	count,
	pending = false,
	onApply,
} ) {
	const [ action, setAction ] = useState( '' );
	const [ confirming, setConfirming ] = useState( null );
	const questionId = useId();
	const applyButton = useRef( null );
	const wasPending = useRef( false );
	const ran = useRef( '' );
	const returnFocus = useRef( false );

	const chosen = actions.find( ( entry ) => entry.value === action ) ?? null;
	const inert = pending || count === 0 || chosen === null;

	// Clear only the action that settled, so a later selection stays available.
	useEffect( () => {
		if ( pending ) {
			wasPending.current = true;
			return;
		}

		if ( wasPending.current ) {
			wasPending.current = false;
			setAction( ( current ) =>
				current === ran.current ? '' : current
			);
		}
	}, [ pending ] );

	// Return focus before Cancel unmounts.
	useLayoutEffect( () => {
		if ( confirming === null && returnFocus.current ) {
			returnFocus.current = false;
			applyButton.current?.focus();
		}
	}, [ confirming ] );

	// The question counts the selection, so it cannot outlive it: a filter change
	// clears the rows underneath and would leave "Delete 3 permanently?" standing
	// over nothing.
	if ( confirming !== null && count === 0 ) {
		setConfirming( null );
	}

	/**
	 * Apply the chosen action, or ask about it first when it cannot be undone.
	 *
	 * @return {void}
	 */
	function handleApply() {
		if ( inert ) {
			return;
		}

		if ( chosen.isDestructive ) {
			setConfirming( chosen );
			return;
		}

		ran.current = chosen.value;
		onApply( chosen.value );
	}

	/**
	 * Go through with the destructive action that was asked about.
	 *
	 * @return {void}
	 */
	function handleConfirm() {
		const target = confirming;
		setConfirming( null );
		ran.current = target.value;
		onApply( target.value );
	}

	/**
	 * Drop the question, and hand the focus back to the button it replaced.
	 *
	 * @return {void}
	 */
	function handleCancel() {
		returnFocus.current = true;
		setConfirming( null );
	}

	return (
		<div
			className="tiermint-bulk-actions"
			role="group"
			aria-label={ label }
		>
			{ confirming !== null ? (
				<>
					{ /* Announce the question without moving focus from the reused button. */ }
					<span
						id={ questionId }
						role="alert"
						className="tiermint-bulk-actions__confirm"
					>
						{ confirming.confirmText( count ) }
					</span>
					{ /* Keep this second so React preserves focus when the button changes. */ }
					<button
						type="button"
						className="button button-primary"
						aria-describedby={ questionId }
						onClick={ handleConfirm }
					>
						{ __( 'Confirm', 'tiermint' ) }
					</button>
					<button
						type="button"
						className="button"
						onClick={ handleCancel }
					>
						{ __( 'Cancel', 'tiermint' ) }
					</button>
				</>
			) : (
				<>
					<select
						className="tiermint-bulk-actions__select"
						aria-label={ __( 'Select bulk action', 'tiermint' ) }
						value={ action }
						onChange={ ( event ) =>
							setAction( event.target.value )
						}
					>
						<option value="">
							{ __( 'Bulk actions', 'tiermint' ) }
						</option>
						{ actions.map( ( entry ) => (
							<option key={ entry.value } value={ entry.value }>
								{ entry.label }
							</option>
						) ) }
					</select>
					{ /* Keep focus on this inert button. The handler rejects the click. */ }
					<button
						ref={ applyButton }
						type="button"
						className="button"
						aria-disabled={ inert }
						aria-busy={ pending || undefined }
						onClick={ handleApply }
					>
						{ pending && (
							<span
								className="tiermint-bulk-actions__spinner"
								aria-hidden="true"
							/>
						) }
						{ __( 'Apply', 'tiermint' ) }
					</button>
				</>
			) }
			<span className="tiermint-bulk-actions__count">
				{ countText( count ) }
			</span>
		</div>
	);
}

/**
 * What a batch did, as one notice.
 *
 * Screens provide the success summary. This component presents each failure.
 *
 * @param {Object}   props           Component props.
 * @param {string}   props.summary   The success summary.
 * @param {Array}    props.failed    Failed `{id, code, message}` entries.
 * @param {Function} props.labelFor  Names one row by id.
 * @param {Function} props.onDismiss Called when the merchant closes the notice.
 * @return {?Element} The notice, or null when there is nothing to say.
 */
export function BulkResultNotice( { summary, failed, labelFor, onDismiss } ) {
	if ( ! summary && failed.length === 0 ) {
		return null;
	}

	return (
		<Notice
			status={ failed.length > 0 ? 'error' : 'success' }
			onRemove={ onDismiss }
		>
			{ summary && <p>{ summary }</p> }
			{ failed.length > 0 && (
				<>
					<p>
						{ sprintf(
							/* translators: %d: how many rows the action could not be applied to. */
							_n(
								'%d failed:',
								'%d failed:',
								failed.length,
								'tiermint'
							),
							failed.length
						) }
					</p>
					<ul>
						{ failed.map( ( entry ) => (
							<li key={ entry.id }>
								{ sprintf(
									/* translators: 1: a label, 2: its value. */
									__( '%1$s: %2$s', 'tiermint' ),
									labelFor( entry.id ) ||
										missingRow( entry.id ),
									entry.message
								) }
							</li>
						) ) }
					</ul>
				</>
			) }
		</Notice>
	);
}
