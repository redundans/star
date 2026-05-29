import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';
import Button from 'flarum/common/components/Button';
import ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';
import type Post from 'flarum/common/models/Post';

// Flarum 2.0 förväntar sig en direkt export-funktion för forumets startpunkt

app.initializers.add('redundans-star-forum', () => {

  extend(CommentPost.prototype, 'actionItems', function (this: CommentPost, items: ItemList<Mithril.Children>) {
    const post = this.attrs.post as Post;

    // Hämta det nya beräknade rättighetsattributet från vår backend
    const canStar = post.attribute<boolean>('canStar') || false;

    // Om användaren inte är inloggad eller saknar admin-rättigheten, rita inte ut knappen
    if (!app.session.user || !canStar) {
      return;
    }

    const isStarred = post.attribute<boolean>('isStarred') || false;

    items.add(
      'star-post',
      Button.component(
        {
          className: `Button Button--link ${isStarred ? 'Post-starButton--active' : ''}`,
          icon: isStarred ? 'fas fa-star' : 'far fa-star',
          onclick: () => {
            const newStatus = !isStarred;

            post.save({ isStarred: newStatus }).then(() => {
              m.redraw();
            });
          },
        },
        isStarred
          ? app.translator.trans('redundans-star.forum.starred')
          : app.translator.trans('redundans-star.forum.star')
      ),
      10
    );
  });

});
