/**
 * TierMint product details tier/quantity enhancement.
 *
 * Keep quantity controls aligned with the selected plan.
 */

/**
 * Enhance one product details root: bind its plan radios to its quantity field.
 *
 * @param {HTMLElement} root The product details container.
 * @return {void}
 */
function enhance( root ) {
	const quantityBlock = root.querySelector(
		'.tiermint-product-details__quantity'
	);
	const quantityInput = root.querySelector(
		'.tiermint-product-details__quantity input[name="quantity"]'
	);
	const radios = Array.prototype.slice.call(
		root.querySelectorAll( 'input[name="price_id"][type="radio"]' )
	);

	// A single-tier product has no quantity rule to update.
	if ( ! quantityBlock || ! quantityInput || 0 === radios.length ) {
		return;
	}

	const fixedBlock = root.querySelector(
		'.tiermint-product-details__quantity-fixed'
	);
	const fixedValue = root.querySelector(
		'.tiermint-product-details__quantity-fixed-value'
	);

	/**
	 * Apply one plan's quantity rule to the field.
	 *
	 * @param {HTMLInputElement} radio The selected plan radio.
	 * @return {void}
	 */
	function applyRule( radio ) {
		if ( '1' === radio.getAttribute( 'data-fixed' ) ) {
			quantityBlock.setAttribute( 'hidden', '' );
			quantityInput.value = '1';
			quantityInput.setAttribute( 'min', '1' );
			quantityInput.setAttribute( 'max', '1' );
			if ( fixedValue ) {
				fixedValue.textContent = '1';
			}
			if ( fixedBlock ) {
				fixedBlock.removeAttribute( 'hidden' );
			}
			return;
		}

		if ( fixedBlock ) {
			fixedBlock.setAttribute( 'hidden', '' );
		}

		const min = Math.max(
			1,
			parseInt( radio.getAttribute( 'data-min' ), 10 ) || 1
		);
		const rawMax = parseInt( radio.getAttribute( 'data-max' ), 10 );
		const max = Number.isNaN( rawMax ) || rawMax <= 0 ? null : rawMax;

		quantityBlock.removeAttribute( 'hidden' );
		quantityInput.setAttribute( 'min', String( min ) );

		if ( null === max ) {
			quantityInput.removeAttribute( 'max' );
		} else {
			quantityInput.setAttribute( 'max', String( max ) );
		}

		let value = parseInt( quantityInput.value, 10 );
		if ( Number.isNaN( value ) || value < min ) {
			value = min;
		}
		if ( null !== max && value > max ) {
			value = max;
		}
		quantityInput.value = String( value );
	}

	radios.forEach( ( radio ) => {
		radio.addEventListener( 'change', () => applyRule( radio ) );
	} );

	const checked = radios.find( ( radio ) => radio.checked );
	if ( checked ) {
		applyRule( checked );
	}
}

document.querySelectorAll( '.tiermint-product-details' ).forEach( enhance );
