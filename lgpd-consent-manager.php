<?php
/** 
 * LGPD Consent Manager
 *
 * Plugin Name: LGPD Consent Manager
 * Plugin URI: https://github.com/manuseiro/lgpd-consent-manager
 * Description: O LGPD Consent Manager permite gerenciar os consentimentos de cookies e dados pessoais dos visitantes conforme as diretrizes da Lei Geral de Proteção de Dados (LGPD). Exibe uma mensagem solicitando o aceite ou recusa e armazena essas informações no banco de dados, além de fornecer uma interface administrativa para auditoria.
 * Version: 1.2.0
 * Author: Manuseiro
 * Author URI: https://github.com/manuseiro
 * Text Domain: lgpd-consent-manager
 * Domain Path: /lang
 * Requires PHP: 7.0
 * Requires at least: 5.0
 */

// Função para registrar o consentimento no banco de dados
function lgpd_save_consent($action) {
    global $wpdb;
    $user_ip = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT']; // Captura o User Agent do navegador
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
    if (!isset($_COOKIE['lgpd_consent'])) {
        echo '<div id="lgpd-consent-banner" style="position: fixed; bottom: 0; background: rgba(0,0,0,0.9); color: #fff; width: 100%; padding: 20px; text-align: center; z-index: 1000;">
                <p>Este site usa cookies para melhorar sua experiência. Ao continuar navegando, você aceita os termos de privacidade. <a href="/politica-de-privacidade" class="btn btn-primary">Leia mais</a></p>
                <button style="background-color: green; color: white; padding: 10px;" onclick="lgpdConsentAction(\'accepted\')">Aceitar</button>
                <button style="background-color: red; color: white; padding: 10px;" onclick="lgpdConsentAction(\'rejected\')">Recusar</button>
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

// Função para criar página de administração no WordPress
function lgpd_create_admin_page() {
    add_menu_page(
        'Gerenciamento de LGPD',
        'LGPD Consentimentos',
        'manage_options',
        'lgpd-consent-manager',
        'lgpd_display_admin_page',
        'dashicons-privacy',
        20
    );
}
add_action('admin_menu', 'lgpd_create_admin_page');

// Função para exibir a lista de consentimentos na página administrativa com filtro e paginação
function lgpd_display_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'lgpd_consent';

    // Filtro por ação (aceitar ou recusar)
    $action_filter = isset($_GET['action_filter']) ? sanitize_text_field($_GET['action_filter']) : '';

    $query = "SELECT * FROM $table_name";
    if ($action_filter) {
        $query .= $wpdb->prepare(" WHERE action = %s", $action_filter);
    }

    // Paginação
    $items_per_page = 10;
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
    $offset = ($current_page - 1) * $items_per_page;

    $results = $wpdb->get_results($query . " LIMIT $items_per_page OFFSET $offset");

    // Exibe os consentimentos
    echo '<div class="wrap"><h1>Lista de Consentimentos LGPD</h1>';

    // Filtro por tipo de ação
    echo '<form method="get"><input type="hidden" name="page" value="lgpd-consent-manager"/>';
    echo '<select name="action_filter" onchange="this.form.submit()">';
    echo '<option value="">Todos</option>';
    echo '<option value="accepted" ' . selected($action_filter, 'accepted', false) . '>Aceitos</option>';
    echo '<option value="rejected" ' . selected($action_filter, 'rejected', false) . '>Recusados</option>';
    echo '</select></form>';

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>ID</th><th>IP do Usuário</th><th>Ação</th><th>Data</th><th>User Agent</th></tr></thead>';
    echo '<tbody>';

    if (!empty($results)) {
        foreach ($results as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row->id) . '</td>';
            echo '<td>' . esc_html($row->ip_address) . '</td>';
            echo '<td>' . esc_html($row->action) . '</td>';
            echo '<td>' . esc_html($row->date) . '</td>';
            echo '<td>' . esc_html($row->user_agent) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="5">Nenhum consentimento registrado ainda.</td></tr>';
    }

    echo '</tbody>';
    echo '</table>';

    // Paginação links
    echo '<div class="tablenav"><div class="tablenav-pages">';
    echo paginate_links(array(
        'base' => add_query_arg('paged', '%#%'),
        'format' => '',
        'prev_text' => __('&laquo;'),
        'next_text' => __('&raquo;'),
        'total' => ceil($total_items / $items_per_page),
        'current' => $current_page
    ));
    echo '</div></div>';

    echo '</div>';
}

// Filtra e armazena apenas os dois primeiros octetos do IP
function lgpd_sanitize_ip($ip_address) {
    $parts = explode('.', $ip_address);
    return isset($parts[0], $parts[1]) ? $parts[0] . '.' . $parts[1] . '.0.0' : '0.0.0.0';
}
