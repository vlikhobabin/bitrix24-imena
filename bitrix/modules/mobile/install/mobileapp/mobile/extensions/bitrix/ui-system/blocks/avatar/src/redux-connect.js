/**
 * @module ui-system/blocks/avatar/src/redux-connect
 */
jn.define('ui-system/blocks/avatar/src/redux-connect', (require, exports, module) => {
	const { usersSelector } = require('statemanager/redux/slices/users');
	const { connect } = require('statemanager/redux/connect');

	const mapStateToProps = (state, props) => {
		const userId = props.id;
		const { name, lastName, avatarSize100 } = usersSelector.selectById(state, Number(userId)) || {};
		const fullName = [name, lastName].filter(Boolean).join(' ');

		return {
			...props,
			uri: avatarSize100,
			name: fullName,
		};
	};

	module.exports = {
		reduxConnect: connect(mapStateToProps),
	};
});
