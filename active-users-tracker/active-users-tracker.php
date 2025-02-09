<?php
/**
 * Plugin Name: Active Users Tracker
 * Plugin URI: https://github.com/aelph/active-users-tracker
 * Description: Отслеживание и отображение активных пользователей WordPress
 * Version: 1.0.0
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
define('AUT_VERSION', '1.0.0');
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

// Добавляем страницу в админку
function add_active_users_menu() {
    // Проверяем, является ли пользователь администратором
    if (!current_user_can('administrator')) {
        return;
    }

    add_menu_page(
        'Активные пользователи',      // Заголовок страницы
        'Активные пользователи',      // Текст меню
        'manage_options',             // Права доступа (только администраторы)
        'active-users',               // Слаг страницы
        'display_active_users_page',  // Функция для отображения содержимого
        'dashicons-groups',           // Иконка меню
        999                          // Позиция в меню
    );
}

// Функция для отображения страницы в админке
function display_active_users_page() {
    // Проверка прав доступа
    if (!current_user_can('manage_options')) {
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
    add_action('comment_post', 'track_comment_activity');
    add_action('admin_init', 'track_admin_activity');
    add_action('admin_menu', 'add_active_users_menu');
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
}
