<?php
/** 
 * LGPD Consent Manager
 *
 * Plugin Name: LGPD Consent Manager
 * Plugin URI: https://github.com/manuseiro/lgpd-consent-manager
 * Description: O LGPD Consent Manager permite gerenciar os consentimentos de cookies e dados pessoais dos visitantes conforme as diretrizes da Lei Geral de Proteção de Dados (LGPD). Exibe uma mensagem solicitando o aceite ou recusa e armazena essas informações no banco de dados, além de fornecer uma interface administrativa para auditoria.
 * Version: 1.4.1
 * Author: Manuseiro
 * Author URI:  https://github.com/manuseiro
 * Text Domain: lgpd-consent-manager
 * Domain Path: /lang
 * Requires PHP: 7.0
 * Requires at least: 5.0
 */

// Função para registrar o consentimento no banco de dados
function lgpd_save_consent($action) {
    global $wpdb;
    $user_ip = sanitize_text_field($_SERVER['REMOTE_ADDR']);
    $user_agent = sanitize_text_field($_SERVER['HTTP_USER_AGENT']);
    $table_name = $wpdb->prefix . 'lgpd_consent';

    // Verifica se o usuário já deu um consentimento
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $table_name WHERE ip_address = %s", $user_ip
    ));

    // Se o usuário já consentiu, atualiza a ação
    if ($existing) {
        $wpdb->update(
            $table_name,
            [
                'action' => $action,
                'date' => current_time('mysql'),
                'user_agent' => $user_agent
            ],
            ['id' => $existing]
        );
    } else {
        $wpdb->insert(
            $table_name,
            [
                'ip_address' => $user_ip,
                'action' => $action,
                'date' => current_time('mysql'),
                'user_agent' => $user_agent
            ]
        );
    }
}

// Função para criar a tabela no banco de dados ao ativar o plugin
function lgpd_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'lgpd_consent';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        ip_address VARCHAR(100) NOT NULL,
        action VARCHAR(20) NOT NULL,
        date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        user_agent VARCHAR(255),
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'lgpd_create_table');

// Exibe a mensagem de aceite no frontend
function lgpd_consent_banner() {
    // Obtém a mensagem e o link da política de privacidade das opções do WordPress
    $message = get_option('lgpd_consent_message', __('This website uses cookies to improve your experience. By continuing to browse, you accept the terms of privacy.', 'lgpd-consent-manager'));
    $privacy_link = get_option('lgpd_privacy_link', '/politica-de-privacidade');

    if (!isset($_COOKIE['lgpd_consent'])) {
        echo '<div id="lgpd-consent-banner" style="position: fixed; bottom: 0; background: rgba(0,0,0,0.9); color: #fff; width: 100%; padding: 20px; text-align: center; z-index: 1000;">
                <p>' . esc_html($message) . ' <a href="' . esc_url($privacy_link) . '" class="btn btn-primary">' . __('Read more', 'lgpd-consent-manager') . '</a></p>
                <button class="btn btn-dark" onclick="lgpdConsentAction(\'accepted\')">' . __('Accept', 'lgpd-consent-manager') . '</button>
                <button class="btn btn-danger" onclick="lgpdConsentAction(\'rejected\')">' . __('Refuse', 'lgpd-consent-manager') . '</button>
              </div>';
    }
}
add_action('wp_footer', 'lgpd_consent_banner');

// Função JavaScript para salvar o consentimento via AJAX
function lgpd_consent_scripts() {
    ?>
    <script type="text/javascript">
        function lgpdConsentAction(action) {
            var xhttp = new XMLHttpRequest();
            xhttp.open("POST", "<?php echo admin_url('admin-ajax.php'); ?>", true);
            xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
            xhttp.onreadystatechange = function() {
                if (this.readyState == 4 && this.status == 200) {
                    document.getElementById('lgpd-consent-banner').style.display = 'none';
                    document.cookie = "lgpd_consent=" + action + "; path=/; expires=365";
                }
            };
            xhttp.send("action=lgpd_save_consent&consent_action=" + action + "&nonce=<?php echo wp_create_nonce('lgpd_nonce'); ?>");
        }
    </script>
    <?php
}
add_action('wp_footer', 'lgpd_consent_scripts');

// Manipula a requisição AJAX para salvar o consentimento
function lgpd_save_consent_ajax() {
    check_ajax_referer('lgpd_nonce', 'nonce'); // Verifica o nonce
    if (isset($_POST['consent_action'])) {
        $action = sanitize_text_field($_POST['consent_action']);
        lgpd_save_consent($action);
    }
    wp_die();
}
add_action('wp_ajax_lgpd_save_consent', 'lgpd_save_consent_ajax');
add_action('wp_ajax_nopriv_lgpd_save_consent', 'lgpd_save_consent_ajax');

// Função para criar a página de administração no WordPress
function lgpd_create_admin_page() {
    add_menu_page(
        __('Accepts LGPD', 'lgpd-consent-manager'),
        __('Accepts LGPD', 'lgpd-consent-manager'),
        'manage_options',
        'lgpd-consent-manager',
        'lgpd_display_admin_page',
        'dashicons-privacy',
        20
    );

    // Submenu para configurações
    add_submenu_page(
        'lgpd-consent-manager',
        __('LGPD Settings', 'lgpd-consent-manager'),
        __('Settings', 'lgpd-consent-manager'),
        'manage_options',
        'lgpd-consent-settings',
        'lgpd_display_settings_page'
    );
}
add_action('admin_menu', 'lgpd_create_admin_page');

// Função para exibir a lista de consentimentos na página administrativa com filtro, ordenação e paginação
function lgpd_display_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'lgpd_consent';

    // Filtro por ação (aceitar ou recusar)
    $action_filter = isset($_GET['action_filter']) ? sanitize_text_field($_GET['action_filter']) : '';

    // Ordenação por coluna
    $order_by = isset($_GET['orderby']) ? sanitize_sql_orderby($_GET['orderby']) : 'date';
    $order = isset($_GET['order']) && in_array($_GET['order'], ['asc', 'desc']) ? $_GET['order'] : 'desc';

    // Base da query SQL
    $query = "SELECT * FROM $table_name";
    if ($action_filter) {
        $query .= $wpdb->prepare(" WHERE action = %s", $action_filter);
    }
    $query .= " ORDER BY $order_by $order";

    // Paginação
    $items_per_page = 10;
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
    $offset = ($current_page - 1) * $items_per_page;

    $results = $wpdb->get_results($query . " LIMIT $items_per_page OFFSET $offset");

    // Exibe os consentimentos
    echo '<div class="wrap">
    <h1>' . __('LGPD Consent List', 'lgpd-consent-manager') . '</h1>';

    // Filtro por tipo de ação
    echo '<form method="get"><input type="hidden" name="page" value="lgpd-consent-manager"/>';
    echo '<select name="action_filter" onchange="this.form.submit()">';
    echo '<option value="">' . __('All', 'lgpd-consent-manager') . '</option>';
    echo '<option value="accepted" ' . selected($action_filter, 'accepted', false) . '>' . __('Accepted', 'lgpd-consent-manager') . '</option>';
    echo '<option value="rejected" ' . selected($action_filter, 'rejected', false) . '>' . __('Refused', 'lgpd-consent-manager') . '</option>';
    echo '</select></form><br>';

    // Tabela de consentimentos com links para ordenação
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th>' . __('ID', 'lgpd-consent-manager') . '</th>';
    echo '<th><a href="' . add_query_arg(['orderby' => 'ip_address', 'order' => ($order == 'asc' ? 'desc' : 'asc')]) . '">' . __('User IP', 'lgpd-consent-manager') . '</a></th>';
    echo '<th>' . __('Action', 'lgpd-consent-manager') . '</th>';
    echo '<th><a href="' . add_query_arg(['orderby' => 'date', 'order' => ($order == 'asc' ? 'desc' : 'asc')]) . '">' . __('Date', 'lgpd-consent-manager') . '</a></th>';
    echo '<th>' . __('User Agent', 'lgpd-consent-manager') . '</th>';
    echo '</tr></thead>';
    
    // Corpo da tabela
    echo '<tbody>';
    foreach ($results as $row) {
        echo '<tr>';
        echo '<td>' . esc_html($row->id) . '</td>';
        echo '<td>' . esc_html($row->ip_address) . '</td>';
        echo '<td>' . esc_html($row->action) . '</td>';
        echo '<td>' . esc_html($row->date) . '</td>';
        echo '<td>' . esc_html($row->user_agent) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    // Paginação
    $total_pages = ceil($total_items / $items_per_page);
    echo '<div class="pagination">';
    for ($i = 1; $i <= $total_pages; $i++) {
        echo '<a href="' . add_query_arg('paged', $i, $_SERVER['REQUEST_URI']) . '">' . $i . '</a> ';
    }
    echo '</div>';

    echo '</div>';
}

// Função para exibir a página de configurações do consentimento
function lgpd_display_settings_page() {
    // Verifica se o usuário tem permissão para gerenciar opções
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to access this page.'));
    }

    // Salva as configurações se o formulário for enviado
    if (isset($_POST['lgpd_update_settings'])) {
        $message = sanitize_textarea_field($_POST['lgpd_consent_message']);
        $privacy_page = intval($_POST['lgpd_privacy_page']);
        update_option('lgpd_consent_message', $message);
        update_option('lgpd_privacy_page', $privacy_page); // Armazena o ID da página
        echo __('<div class="updated"><p>Settings updated successfully!</p></div>', 'lgpd-consent-manager');
    }

    // Obtém as configurações atuais
    $current_message = get_option('lgpd_consent_message', 'This website uses cookies to improve your experience. By continuing to browse, you accept the terms of privacy.');
    $current_privacy_page = get_option('lgpd_privacy_page');

    // Formulário de configurações
    echo __('<div class="wrap"><h1>Consent Settings</h1>', 'lgpd-consent-manager');
    echo '<form method="post">';
    echo __('<h2>Message</h2>', 'lgpd-consent-manager');
    echo '<textarea name="lgpd_consent_message" rows="5" style="width: 100%;">' . esc_textarea($current_message) . '</textarea>';
    
    // Obtém as páginas publicadas
    $pages = get_pages();
    ?>

<h2><?php echo  __('Select Privacy Policy', 'lgpd-consent-manager')?></h2>
    <select name="lgpd_privacy_page" style="width: 100%;">
        <option value=""><?php echo  __('Select a page', 'lgpd-consent-manager')?></option>
        <?php
        // Preenche o select com as páginas disponíveis
        foreach ($pages as $page) {
            $selected = ($current_privacy_page == $page->ID) ? 'selected' : '';
            echo '<option value="' . esc_attr($page->ID) . '" ' . $selected . '>' . esc_html($page->post_title) . '</option>';
        }
        ?>
    </select>

    <br><br><input type="submit" name="lgpd_update_settings" class="button button-primary" value="<?php echo __('Save Settings', 'lgpd-consent-manager')?>" />
    </form>
    </div>
    <?php
}

// Registra as configurações
function lgpd_register_settings() {
    register_setting('lgpd_settings_group', 'lgpd_consent_message');
    register_setting('lgpd_settings_group', 'lgpd_privacy_link');
}
add_action('admin_init', 'lgpd_register_settings');

