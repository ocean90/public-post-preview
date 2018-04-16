import { settings } from '@wordpress/date';
import { DateTimePicker } from '@wordpress/components';

export function PreviewUntil( { date, onUpdateDate } ) {
	// To know if the current timezone is a 12 hour time with look for "a" in the time format
	// We also make sure this a is not escaped by a "/"
	const is12HourTime = /a(?!\\)/i.test(
		settings.formats.time
			.toLowerCase() // Test only the lower case a
			.replace( /\\\\/g, '' ) // Replace "//" with empty strings
			.split( '' ).reverse().join( '' ) // Reverse the string and test for "a" not followed by a slash
	);

	return (
		<DateTimePicker
			key="date-time-picker"
			currentDate={ date }
			onChange={ onUpdateDate }
			locale={ settings.l10n.locale }
			is12Hour={ is12HourTime }
		/>
	);
}
