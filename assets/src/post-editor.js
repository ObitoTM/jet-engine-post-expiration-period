const { registerPlugin } = wp.plugins;
const PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel || wp.editPost.PluginDocumentSettingPanel;
const { useSelect, useDispatch } = wp.data;
const { useEffect } = wp.element;
const { ToggleControl, TextControl, SelectControl } = wp.components;
const { __ } = wp.i18n;
const domain = 'jet-engine-post-expiration-period';
const fieldName = 'jet_pep_expiration';
const saveLock = 'jet-engine-pep-expiration';

function PostExpirationPanel() {
	const { expiration, changed } = useSelect( select => {
		const editor = select( 'core/editor' );
		return {
			expiration: editor.getEditedPostAttribute( fieldName ),
			changed: Object.prototype.hasOwnProperty.call( editor.getPostEdits(), fieldName ),
		};
	}, [] );
	const { editPost, lockPostSaving, unlockPostSaving } = useDispatch( 'core/editor' );
	const missingDate = !! ( expiration && expiration.enabled && !expiration.date && changed );

	useEffect( () => {
		if ( missingDate ) {
			lockPostSaving( saveLock );
		} else {
			unlockPostSaving( saveLock );
		}
		return () => unlockPostSaving( saveLock );
	}, [ missingDate, lockPostSaving, unlockPostSaving ] );

	if ( !expiration ) {
		return null;
	}
	const update = changes => editPost( { [ fieldName ]: { ...expiration, ...changes } } );

	return <PluginDocumentSettingPanel
		name="post-expiration"
		title={ __( 'Post Expiration', domain ) }
		className="jet-pep-document-panel"
	>
		<div className="jet-pep-document-panel__controls">
			<ToggleControl
				label={ __( 'Enable expiration', domain ) }
				checked={ expiration.enabled }
				onChange={ enabled => update( { enabled } ) }
				__nextHasNoMarginBottom
			/>
			{ expiration.enabled && <wp.element.Fragment>
				<TextControl
					className={ missingDate ? 'jet-pep-date-error' : undefined }
					label={ __( 'Expires on', domain ) }
					type="date"
					value={ expiration.date }
					aria-invalid={ missingDate }
					help={ missingDate ? __( 'Select an expiration date.', domain ) : undefined }
					onChange={ date => update( { date } ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'On expiration', domain ) }
					value={ expiration.action }
					options={ [
						{ label: __( 'Set to draft', domain ), value: 'draft' },
						{ label: __( 'Move to trash', domain ), value: 'trash' },
					] }
					onChange={ action => update( { action } ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			</wp.element.Fragment> }
		</div>
	</PluginDocumentSettingPanel>;
}

registerPlugin( 'jet-engine-post-expiration', { render: PostExpirationPanel, icon: null } );
