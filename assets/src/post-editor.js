const { registerPlugin } = wp.plugins;
const PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel || wp.editPost.PluginDocumentSettingPanel;
const { useSelect, useDispatch } = wp.data;
const { useEffect, useState } = wp.element;
const { ToggleControl, TextControl, SelectControl, Notice } = wp.components;
const { __ } = wp.i18n;
const domain = 'jet-engine-post-expiration-period';
const fieldName = 'jet_pep_expiration';
const saveLock = 'jet-engine-pep-expiration';

function getSiteDate() {
	const { timezone } = wp.date.getSettings();
	const now = new Date();
	if ( !timezone.string ) {
		now.setTime( now.getTime() + Number( timezone.offset ) * 3600000 );
	}
	const parts = new Intl.DateTimeFormat( 'en-US', {
		timeZone: timezone.string || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit',
	} ).formatToParts( now );
	const part = type => parts.find( item => item.type === type ).value;
	return `${ part( 'year' ) }-${ part( 'month' ) }-${ part( 'day' ) }`;
}

function PostExpirationPanel() {
	const { expiration, original, changed } = useSelect( select => {
		const editor = select( 'core/editor' );
		return {
			expiration: editor.getEditedPostAttribute( fieldName ),
			original: editor.getCurrentPostAttribute( fieldName ),
			changed: Object.prototype.hasOwnProperty.call( editor.getPostEdits(), fieldName ),
		};
	}, [] );
	const { editPost, lockPostSaving, unlockPostSaving } = useDispatch( 'core/editor' );
	const [ today, setToday ] = useState( getSiteDate );
	useEffect( () => {
		const refreshDate = () => setToday( getSiteDate() );
		window.addEventListener( 'focus', refreshDate );
		document.addEventListener( 'visibilitychange', refreshDate );
		const timer = window.setInterval( refreshDate, 60000 );
		return () => {
			window.removeEventListener( 'focus', refreshDate );
			document.removeEventListener( 'visibilitychange', refreshDate );
			window.clearInterval( timer );
		};
	}, [] );

	const sameDate = !! ( original && original.enabled && expiration && expiration.date === original.date );
	const unchangedTrash = sameDate && original.action === 'trash' && expiration.action === 'trash';
	const canTrash = window.jetPEP.canTrash;
	const trashEnabled = window.jetPEP.trashEnabled !== false;
	const needsValidation = expiration && expiration.enabled && changed;
	const dateError = needsValidation && ( !expiration.date
		? __( 'Select an expiration date.', domain )
		: ( !sameDate && expiration.date <= today ? __( 'Select a future expiration date.', domain ) : '' ) );
	const trashError = needsValidation && expiration.action === 'trash' && !canTrash && !unchangedTrash;
	const invalid = !! ( dateError || trashError );
	const tomorrow = new Date( today + 'T00:00:00Z' );
	tomorrow.setUTCDate( tomorrow.getUTCDate() + 1 );

	useEffect( () => {
		if ( invalid ) {
			lockPostSaving( saveLock );
		} else {
			unlockPostSaving( saveLock );
		}
		return () => unlockPostSaving( saveLock );
	}, [ invalid, lockPostSaving, unlockPostSaving ] );

	if ( !expiration ) {
		return null;
	}
	const update = changes => editPost( { [ fieldName ]: { enabled: expiration.enabled, date: expiration.date, action: expiration.action, ...changes } } );

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
				{ original?.legacy_expired && sameDate && <Notice status="warning" isDismissible={ false } className="jet-pep-document-panel__notice">
					{ __( 'This expiration was missed and will not run. Set a new date or disable expiration.', domain ) }
				</Notice> }
				{ !trashEnabled && unchangedTrash && <Notice status="warning" isDismissible={ false } className="jet-pep-document-panel__notice">
					{ __( 'The trash is disabled. Choose Draft or disable expiration.', domain ) }
				</Notice> }
				<TextControl
					className={ dateError ? 'jet-pep-control-error' : undefined }
					label={ __( 'Expires on', domain ) }
					type="date"
					value={ expiration.date }
					min={ sameDate ? undefined : tomorrow.toISOString().slice( 0, 10 ) }
					aria-invalid={ !! dateError }
					help={ dateError || undefined }
					onChange={ date => {
						setToday( getSiteDate() );
						update( { date } );
					} }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				<SelectControl
					className={ trashError ? 'jet-pep-control-error' : undefined }
					label={ __( 'On expiration', domain ) }
					help={ trashError ? ( trashEnabled ? __( 'You cannot schedule moving this post to trash.', domain ) : __( 'The trash is disabled. Choose Draft or disable expiration.', domain ) ) : undefined }
					value={ expiration.action }
					options={ [
						{ label: __( 'Set to draft', domain ), value: 'draft' },
						{ label: __( 'Move to trash', domain ), value: 'trash', disabled: !canTrash && !unchangedTrash },
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
