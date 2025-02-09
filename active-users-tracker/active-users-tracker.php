<?php
/**
 * Plugin Name: Active Users Tracker
 * Plugin URI: https://github.com/aelph/active-users-tracker
 * Description: Отслеживание и отображение активных пользователей WordPress
 * Version: 1.2.0
 * Author: Alex Elph
 * Text Domain: active-users-tracker
 * Domain Path: /languages
 *
 * @package Active_Users_Tracker
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Определяем константы плагина
define('AUT_VERSION', '1.2.0');
define('AUT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AUT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Функция для получения списка активных пользователей 
function get_all_wordpress_users() {
    $users = get_users(['fields' => ['ID', 'user_login', 'user_email', 'user_registered', 'display_name']]);
    $current_time = current_time('timestamp');
    $active_users = [];
    $activity_period = 30 * 24 * 60 * 60; // 30 дней, 24 часа, 60 минут и 60 секунд.

    foreach ($users as $user) {
        // Проверяем разные типы активности
        $last_activity = get_user_meta($user->ID, 'last_activity', true);
        $last_login = get_user_meta($user->ID, 'last_login', true);
        
        // Получаем время последнего комментария
        $last_comment = get_comments(array(
            'user_id' => $user->ID,
            'number' => 1,
            'orderby' => 'comment_date_gmt',
            'order' => 'DESC'
        ));
        $last_comment_time = $last_comment ? strtotime($last_comment[0]->comment_date_gmt) : 0;

        // Получаем время последнего поста
        $last_post = get_posts(array(
            'author' => $user->ID,
            'posts_per_page' => 1,
            'orderby' => 'modified',
            'order' => 'DESC',
            'post_type' => 'any',
            'post_status' => 'any'
        ));
        $last_post_time = $last_post ? strtotime($last_post[0]->post_modified_gmt) : 0;

        // Находим самое позднее время активности
        $latest_activity = max(
            intval($last_activity),
            intval($last_login),
            $last_comment_time,
            $last_post_time
        );

        // Если есть хоть какая-то активность за последние 30 дней
        if ($latest_activity && ($current_time - $latest_activity) <= $activity_period) {
            $user->last_activity = $latest_activity;
            $active_users[] = $user;
        }
    }

    // Сортировка по времени последней активности
    usort($active_users, function($a, $b) {
        return $b->last_activity - $a->last_activity;
    });

    foreach ($active_users as $user) {
        $last_active = human_time_diff($user->last_activity, $current_time);
        $avatar = get_avatar($user->ID, 64);
        if (empty($avatar)) {
            $avatar = '<img src="' . admin_url('images/mystery-person.png') . '" width="64" height="64" class="avatar" />';
        }

        // Начало карточки
        echo "<!-- START USER CARD -->\n";
        echo '<div class="user-card">';
        echo $avatar;
        echo '<div class="user-info">';
        
        // Получаем роль пользователя
        $user_data = get_userdata($user->ID);
        $user_roles = $user_data->roles;
        $role_names = array(
            'administrator' => 'Администратор',
            'editor' => 'Редактор',
            'author' => 'Автор',
            'contributor' => 'Участник',
            'subscriber' => 'Подписчик'
        );
        $user_role = isset($user_roles[0]) && isset($role_names[$user_roles[0]]) ? $role_names[$user_roles[0]] : 'Пользователь';
        
        // Информация о пользователе
        $user_info = sprintf(
            '<div class="user-name">%s: %s</div>' .
            '<div class="user-id">ID: %d</div>' .
            '<div class="user-email">Email: %s</div>' .
            '<div class="user-activity">Последняя активность: %s назад</div>' .
            '<div class="user-registered">Дата регистрации: %s</div>',
            esc_html($user_role),
            esc_html($user->display_name),
            esc_html($user->ID),
            esc_html($user->user_email),
            esc_html($last_active),
            esc_html($user->user_registered)
        );
        
        echo $user_info;
        
        // Закрываем блоки
        echo '</div>'; // Закрываем .user-info
        echo '</div>'; // Закрываем .user-card
        echo "<!-- END USER CARD -->\n";
    }
}

// Функция обновления времени активности пользователя
function update_user_last_activity($user_id) {
    update_user_meta($user_id, 'last_activity', current_time('timestamp'));
}

// Отслеживание входа
function track_user_login($user_login) {
    $user = get_user_by('login', $user_login);
    if ($user) {
        update_user_last_activity($user->ID);
    }
}

// Отслеживание публикации и редактирования постов
function track_post_activity($post_id, $post = null, $update = false) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!$post) {
        $post = get_post($post_id);
    }

    if (!$post || !is_object($post)) {
        return;
    }

    $user_id = $post->post_author;
    if ($user_id) {
        update_user_last_activity($user_id);
    }
}

// Отслеживание комментариев
function track_comment_activity($comment_id) {
    $comment = get_comment($comment_id);
    if ($comment && $comment->user_id) {
        update_user_last_activity($comment->user_id);
    }
}

// Отслеживание запросов к админке
function track_admin_activity() {
    $user_id = get_current_user_id();
    if ($user_id) {
        update_user_last_activity($user_id);
    }
}

// Функция для получения разрешенных ролей
function get_allowed_roles() {
    $allowed_roles = get_option('aut_allowed_roles', ['administrator']);
    return is_array($allowed_roles) ? $allowed_roles : ['administrator'];
}

// Проверка доступа пользователя
function user_can_view_active_users() {
    if (!is_user_logged_in()) {
        return false;
    }

    // Администраторы всегда имеют доступ
    if (current_user_can('administrator')) {
        return true;
    }

    $user = wp_get_current_user();
    $allowed_roles = get_allowed_roles();

    // Проверяем, есть ли у пользователя хотя бы одна из разрешенных ролей
    foreach ($user->roles as $role) {
        if (in_array($role, $allowed_roles)) {
            return true;
        }
    }

    return false;
}

// Добавляем страницу в админку
function add_active_users_menu() {
    // Получаем позицию меню из настроек
    $menu_position = get_option('aut_menu_position', 2);

    // Добавляем основную страницу
    add_menu_page(
        'Активные пользователи',      // Заголовок страницы
        'Активные пользователи',      // Текст меню
        'read',                       // Базовые права (чтение)
        'active-users',               // Слаг страницы
        'display_active_users_page',  // Функция отображения
        'dashicons-groups',           // Иконка
        intval($menu_position)        // Позиция
    );

    // Добавляем подстраницу настроек (только для администраторов)
    if (current_user_can('administrator')) {
        add_submenu_page(
            'active-users',              // Родительский слаг
            'Настройки отображения для ролей',  // Заголовок страницы
            'Настройки',                 // Текст меню
            'manage_options',            // Права доступа
            'active-users-settings',     // Слаг
            'display_settings_page'      // Функция отображения
        );
    }

    // Скрываем меню для неразрешенных пользователей
    if (!user_can_view_active_users()) {
        remove_menu_page('active-users');
    }
}

// Функция для отображения страницы в админке
function display_active_users_page() {
    // Проверка прав доступа
    if (!user_can_view_active_users()) {
        wp_die('У вас нет прав для просмотра этой страницы.');
    }
    
    // Добавляем стили
    wp_enqueue_style('active-users-tracker-admin', AUT_PLUGIN_URL . 'css/admin.css', [], AUT_VERSION);
    
    echo '<div class="wrap active-users-wrap">';
    echo '<h1>' . esc_html__('Активные пользователи', 'active-users-tracker') . '</h1>';
    echo '<p class="description">Показываются только те пользователи, которые были активны за последние 30 дней.</p>';
    echo '<p class="description">Отслеживается несколько параметров активности: вход, действия в админке, редактирование публикаций и написание комментариев.</p>';
    echo '<div class="active-users-section">';
    
    ob_start();
    get_all_wordpress_users();
    $users_list = ob_get_clean();
    
    // Разделяем пользователей в отладочной информации
    $users_list = str_replace('----------------------<br>', '<hr>', $users_list);
    
    echo $users_list;
    echo '</div>';
    
    // Добавляем раздел со всеми пользователями
    echo '<div class="all-users-section">';
    echo '<h2>Все пользователи</h2>';
    
    // Получаем всех пользователей
    $all_users = get_users(['fields' => ['ID', 'user_login', 'display_name', 'user_email']]);
    
    // Создаем массив с данными для сортировки
    $users_data = array();
    foreach ($all_users as $user) {
        $last_login = get_user_meta($user->ID, 'last_login', true);
        $users_data[] = array(
            'id' => $user->ID,
            'display_name' => $user->display_name,
            'user_login' => $user->user_login,
            'user_email' => $user->user_email,
            'last_login' => $last_login ? $last_login : 0,
            'last_login_display' => $last_login ? date('d.m.Y H:i', $last_login) : 'нет данных'
        );
    }

    // Сортируем по last_login по убыванию
    usort($users_data, function($a, $b) {
        return $b['last_login'] - $a['last_login'];
    });

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Последний вход</th>';
    echo '<th>Имя</th>';
    echo '<th>Ник</th>';
    echo '<th>E-mail</th>';
    echo '<th>ID</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    foreach ($users_data as $user) {
        echo sprintf(
            '<tr>' .
            '<td>%s</td>' .
            '<td>%s</td>' .
            '<td>%s</td>' .
            '<td>%s</td>' .
            '<td>%d</td>' .
            '</tr>',
            esc_html($user['last_login_display']),
            esc_html($user['display_name']),
            esc_html($user['user_login']),
            esc_html($user['user_email']),
            $user['id']
        );
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

// Регистрация хуков
function aut_init() {
    add_action('wp_login', 'track_user_login');
    add_action('save_post', 'track_post_activity', 10, 3);
    add_action('post_updated', 'track_post_activity', 10, 3);
    add_action('wp_insert_comment', 'track_comment_activity');
    add_action('admin_init', 'track_admin_activity');
    add_action('admin_menu', 'add_active_users_menu');
    
    // Добавляем ссылку на настройки в список плагинов
    $plugin_file = plugin_basename(__FILE__);
    add_filter("plugin_action_links_$plugin_file", 'aut_add_settings_link');
}

// Функция отображения страницы настроек
function display_settings_page() {
    if (!current_user_can('administrator')) {
        wp_die('У вас нет прав для просмотра этой страницы.');
    }

    // Сохранение настроек
    if (isset($_POST['aut_save_settings']) && check_admin_referer('aut_settings_nonce')) {
        // Сохраняем разрешенные роли
        $allowed_roles = isset($_POST['aut_allowed_roles']) ? (array)$_POST['aut_allowed_roles'] : [];
        
        // Всегда добавляем администратора в список
        if (!in_array('administrator', $allowed_roles)) {
            $allowed_roles[] = 'administrator';
        }
        update_option('aut_allowed_roles', $allowed_roles);

        // Сохраняем позицию меню
        $old_position = get_option('aut_menu_position', 2);
        $menu_position = isset($_POST['aut_menu_position']) ? intval($_POST['aut_menu_position']) : 2;
        update_option('aut_menu_position', $menu_position);

        // Если позиция изменилась, перезагружаем страницу
        if ($old_position != $menu_position) {
            echo '<div class="notice notice-success"><p>Настройки сохранены. Страница будет перезагружена...</p></div>';
            echo '<script>setTimeout(function() { window.location.reload(); }, 1000);</script>';
        } else {
            echo '<div class="notice notice-success"><p>Настройки сохранены.</p></div>';
        }
    }

    // Получаем все роли WordPress
    $wp_roles = wp_roles();
    $all_roles = $wp_roles->roles;
    $allowed_roles = get_allowed_roles();

    // Выводим форму настроек
    ?>
    <div class="wrap">
        <h1>Настройки Active Users Tracker</h1>
        <form method="post" action="">
            <?php wp_nonce_field('aut_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Роли, которым разрешён просмотр</th>
                    <td>
                        <p class="description">Выберите роли, которым будет доступна страница активных пользователей.<br>Администраторы всегда имеют доступ.</p></br>
                        <?php foreach ($all_roles as $role_id => $role) : ?>
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="checkbox" 
                                       name="aut_allowed_roles[]" 
                                       value="<?php echo esc_attr($role_id); ?>" 
                                       <?php checked($role_id === 'administrator' ? true : in_array($role_id, $allowed_roles)); ?>
                                       <?php disabled($role_id === 'administrator'); ?>
                                >
                                <?php echo esc_html($role['name']); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Позиция в меню</th>
                    <td>
                        <p class="description">Укажите позицию меню в админ-панели (1-999). Чем меньше число, тем выше будет расположен пункт меню.</p></br>
                        <input type="number" 
                               name="aut_menu_position" 
                               value="<?php echo esc_attr(get_option('aut_menu_position', 2)); ?>" 
                               min="1" 
                               max="999"
                               step="1"
                        >
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="aut_save_settings" class="button button-primary" value="Сохранить настройки">
            </p>
        </form>
    </div>
    <?php
}

// Добавляем ссылку на настройки в список плагинов
function aut_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=active-users-settings') . '">' . __('Настройки', 'active-users-tracker') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Инициализация плагина
add_action('init', 'aut_init');

// Активация плагина
register_activation_hook(__FILE__, 'aut_activate');

function aut_activate() {
    // Создаем директорию для CSS если её нет
    $css_dir = AUT_PLUGIN_DIR . 'css';
    if (!file_exists($css_dir)) {
        wp_mkdir_p($css_dir);
    }

    // Устанавливаем начальные настройки
    if (!get_option('aut_allowed_roles')) {
        update_option('aut_allowed_roles', ['administrator']);
    }

    // Устанавливаем начальную позицию меню
    if (!get_option('aut_menu_position')) {
        update_option('aut_menu_position', 2);
    }
}
