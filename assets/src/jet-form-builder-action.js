const { addFilter } = wp.hooks;
const { __ } = wp.i18n;
const { Fragment } = wp.element;
const { ToggleControl, RadioControl, SelectControl, TextControl } = wp.components;
const NumberControl = wp.components.NumberControl || wp.components.__experimentalNumberControl;
const domain = 'jet-engine-post-expiration-period';

function ExpirationFieldControl( { value, onChange } ) {
	const fields = window.JetFBHooks.useFields( {
		withInner: false,
		placeholder: __( 'Select a field...', domain ),
	} );

	return <SelectControl
		label={ __( 'Form field', domain ) }
		value={ value }
		options={ fields }
		onChange={ onChange }
		__next40pxDefaultSize
		__nextHasNoMarginBottom
	/>;
}

addFilter( 'jet.fb.render.action.insert_post', domain, () => props => {
	const { settings, onChangeSetting } = props;
	const source = settings.expiration_source || 'fixed';
	const valueType = source === 'fixed' ? 'days' : ( settings.expiration_value_type || 'days' );
	const applyOn = settings.expiration_apply_on || 'create';

	return <div className="jet-pep-action-settings">
		<ToggleControl
			label={ __( 'Enable expiration', domain ) }
			checked={ !! settings.enable_expiration_period }
			onChange={ value => onChangeSetting( value, 'enable_expiration_period' ) }
			__nextHasNoMarginBottom
		/>
		{ settings.enable_expiration_period && <Fragment>
			<SelectControl
				label={ __( 'Apply on', domain ) }
				value={ applyOn }
				options={ [
					{ label: __( 'Create only', domain ), value: 'create' },
					{ label: __( 'Create and update', domain ), value: 'create_update' },
				] }
				onChange={ value => onChangeSetting( value, 'expiration_apply_on' ) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<SelectControl
				label={ __( 'Value source', domain ) }
				value={ source }
				options={ [
					{ label: __( 'Manual', domain ), value: 'fixed' },
					{ label: __( 'Form field', domain ), value: 'field' },
					{ label: __( 'Post meta', domain ), value: 'post_meta' },
				] }
				onChange={ value => onChangeSetting( value, 'expiration_source' ) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			{ source !== 'fixed' && <SelectControl
				label={ __( 'Value type', domain ) }
				value={ valueType }
				options={ [
					{ label: __( 'Days', domain ), value: 'days' },
					{ label: __( 'Date', domain ), value: 'date' },
				] }
				onChange={ value => onChangeSetting( value, 'expiration_value_type' ) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/> }
			{ source === 'fixed' &&
				<NumberControl
					label={ __( 'Days', domain ) }
					min={ 1 }
					max={ 365000 }
					step={ 1 }
					value={ settings.expiration_period || '' }
					onChange={ value => onChangeSetting( value, 'expiration_period' ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/> }
			{ source === 'field' && <ExpirationFieldControl
				value={ settings.expiration_field || '' }
				onChange={ value => onChangeSetting( value, 'expiration_field' ) }
			/> }
			{ source === 'post_meta' && <TextControl
				label={ __( 'Meta key', domain ) }
				value={ settings.expiration_meta_key || '' }
				onChange={ value => onChangeSetting( value, 'expiration_meta_key' ) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/> }
			{ valueType === 'date' && <p className="description jet-pep-action-settings__help">
				{ __( 'Future date (YYYY-MM-DD or Unix timestamp).', domain ) }
			</p> }
			<RadioControl
				label={ __( 'On expiration', domain ) }
				className="jet-inline-radio"
				selected={ settings.expiration_action || 'draft' }
				options={ [
					{ label: __( 'Set to draft', domain ), value: 'draft' },
					{ label: __( 'Move to trash', domain ), value: 'trash' },
				] }
				onChange={ value => onChangeSetting( value, 'expiration_action' ) }
				__nextHasNoMarginBottom
			/>
		</Fragment> }
	</div>;
} );
