import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import {
	Panel,
	PanelBody,
	PanelRow,
	FormToggle,
	Dropdown
} from '@wordpress/components';
import { __experimental as editPost } from '@wordpress/edit-post';
import { registerPlugin } from '@wordpress/plugins';
import { PreviewUntil } from './components/preview-until';
import { dateI18n, settings } from '@wordpress/date';

// Destructure experimental components.
const { PluginSidebar, PluginMoreMenuItem } = editPost;

let previewStatus = true;
let previewUntil  = new Date();

const Component = () => (
	<Fragment>
		<PluginMoreMenuItem
			name="public-post-preview"
			type="sidebar"
			target="public-post-preview"
		>
			{ __( 'Public Post Preview', 'public-post-preview' ) }
		</PluginMoreMenuItem>
		<PluginSidebar
			name="public-post-preview"
			title={ __( 'Public Post Preview', 'public-post-preview' ) }
		>
			<Panel>
				<PanelBody>
					<PanelRow>
						<label htmlFor="public-post-preview-status">{ __( 'Enable', 'public-post-preview' ) }</label>
						<FormToggle
							checked={ previewStatus }
							showHint={ false }
							onChange={ () => previewStatus = ! previewStatus }
							id="public-post-preview-status"
						/>
					</PanelRow>
					{ previewStatus && [
						<PanelRow key="foobar">
							<span>{ __( 'Valid until', 'public-post-preview' ) }</span>
							<Dropdown
								position="bottom left"
								contentClassName="edit-post-post-schedule__dialog"
								renderToggle={ ( { onToggle, isOpen } ) => (
									<button
										type="button"
										className="button-link"
										onClick={ onToggle }
										aria-expanded={ isOpen }
									>
										{ dateI18n( settings.formats.datetime, previewUntil ) }
									</button>
								) }
								renderContent={ () => <PreviewUntil
									date={ previewUntil }
									onUpdateDate={ ( date ) => previewUntil = date }
									/> }
							/>
						</PanelRow>,
						<PanelRow key="foobarfoo">
							<label htmlFor="public-post-preview-url">{ __( 'URL', 'public-post-preview' ) }</label>
							<input type="text" id="public-post-preview-url" value="http://src.wp.test/?p=yolo" readOnly />
						</PanelRow>
					] }
				</PanelBody>
			</Panel>
		</PluginSidebar>
	</Fragment>
);

registerPlugin( 'public-post-preview', {
	render: Component,
} );
