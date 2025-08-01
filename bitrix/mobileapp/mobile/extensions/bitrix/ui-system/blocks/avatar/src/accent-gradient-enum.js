/**
 * @module ui-system/blocks/avatar/src/accent-gradient-enum
 */
jn.define('ui-system/blocks/avatar/src/accent-gradient-enum', (require, exports, module) => {
	const { BaseEnum } = require('utils/enums/base');

	/**
	 * @class AccentGradient
	 * @template TAccentGradient
	 * @extends {BaseEnum<AccentGradient>}
	 */
	class AccentGradient extends BaseEnum
	{
		static GREEN = new AccentGradient('GREEN', ['#1BCE42', '#BBED21', '#26D357']);

		static BLUE = new AccentGradient('BLUE', ['#86FFC7', '#0075FF']);
	}

	module.exports = { AccentGradient };
});
