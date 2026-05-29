import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders'; // Importera Flarum 2.0 JS-extenders

export default [
	new Extend.Admin()
	.permission(
		() => ({
			icon: 'fas fa-star',
			label: app.translator.trans('redundans-star.admin.permissions.star_posts_label'),
			permission: 'redundans-star.star_posts',
		}),
		'reply'
	)
];
