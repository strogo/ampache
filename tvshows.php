<?php
/* vim:set softtabstop=4 shiftwidth=4 expandtab: */
/**
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright 2001 - 2020 Ampache.org
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

$a_root = realpath(__DIR__);
require_once $a_root . '/lib/init.php';

UI::show_header();

// Switch on the actions
switch ($_REQUEST['action']) {
    case 'delete':
        if (AmpConfig::get('demo_mode')) {
            break;
        }

        $tvshow_id = (string) scrub_in($_REQUEST['tvshow_id']);
        show_confirmation(T_('Are You Sure?'),
            T_("The TV Show and its files will be deleted"),
            AmpConfig::get('web_path') . "/tvshows.php?action=confirm_delete&tvshow_id=" . $tvshow_id,
            1,
            'delete_tvshow'
        );
        break;
    case 'confirm_delete':
        if (AmpConfig::get('demo_mode')) {
            break;
        }

        $tvshow = new TVShow($_REQUEST['tvshow_id']);
        if (!Catalog::can_remove($tvshow)) {
            debug_event('tvshows', 'Unauthorized to remove the tvshow `.' . $tvshow->id . '`.', 1);
            UI::access_denied();

            return false;
        }

        if ($tvshow->remove()) {
            show_confirmation(T_('No Problem'), T_('TV Show has been deleted'), AmpConfig::get('web_path'));
        } else {
            show_confirmation(T_("There Was a Problem"), T_("Couldn't delete this TV Show"), AmpConfig::get('web_path'));
        }
        break;
    case 'show':
        $tvshow = new TVShow($_REQUEST['tvshow']);
        $tvshow->format();
        $object_ids  = $tvshow->get_seasons();
        $object_type = 'tvshow_season';
        require_once AmpConfig::get('prefix') . UI::find_template('show_tvshow.inc.php');
        break;
} // end switch

// Show the Footer
UI::show_query_stats();
UI::show_footer();
