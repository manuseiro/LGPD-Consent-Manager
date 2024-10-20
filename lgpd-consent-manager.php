<?php
/** 
 * LGPD Consent Manager
 *
 * Plugin Name: LGPD Consent Manager
 * Plugin URI: https://github.com/manuseiro/lgpd-consent-manager
 * Description: O LGPD Consent Manager permite gerenciar os consentimentos de cookies e dados pessoais dos visitantes conforme as diretrizes da Lei Geral de Proteção de Dados (LGPD). Exibe uma mensagem solicitando o aceite ou recusa e armazena essas informações no banco de dados, além de fornecer uma interface administrativa para auditoria.
 * Version: 1.3.1
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
    // Obtém a mensagem e o link da política de privacidade das opções do WordPress
    $message = get_option('lgpd_consent_message', 'Este site usa cookies para melhorar sua experiência. Ao continuar navegando, você aceita os termos de privacidade.');
    $privacy_link = get_option('lgpd_privacy_link', '/politica-de-privacidade');

    if (!isset($_COOKIE['lgpd_consent'])) {
        echo '<div id="lgpd-consent-banner" style="position: fixed; bottom: 0; background: rgba(0,0,0,0.9); color: #fff; width: 100%; padding: 20px; text-align: center; z-index: 1000;">
                <p>' . esc_html($message) . ' <a href="' . esc_url($privacy_link) . '" class="btn btn-primary">Leia mais</a></p>
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

// Função para criar a página de administração no WordPress
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

    // Submenu para configurações
    add_submenu_page(
        'lgpd-consent-manager',
        'Configurações de Consentimento',
        'Configurações',
        'manage_options',
        'lgpd-consent-settings',
        'lgpd_display_settings_page'
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
        echo '<tr><td colspan="5">Nenhum consentimento encontrado.</td></tr>';
    }

    echo '</tbody></table>';

    // Paginação
    $total_pages = ceil($total_items / $items_per_page);
    echo '<div class="pagination">';
    for ($i = 1; $i <= $total_pages; $i++) {
        echo '<a href="?page=lgpd-consent-manager&paged=' . $i . '&action_filter=' . esc_attr($action_filter) . '">' . $i . '</a> ';
    }
    echo '</div>';
    echo '</div>';
}

// Função para exibir a página de configurações do consentimento
function lgpd_display_settings_page() {
    // Verifica se o usuário tem permissão para gerenciar opções
    if (!current_user_can('manage_options')) {
        wp_die(__('Você não tem permissão para acessar esta página.'));
    }

    // Salva as configurações se o formulário for enviado
    if (isset($_POST['lgpd_update_settings'])) {
        $message = sanitize_textarea_field($_POST['lgpd_consent_message']);
        $privacy_page = intval($_POST['lgpd_privacy_page']);
        update_option('lgpd_consent_message', $message);
        update_option('lgpd_privacy_page', $privacy_page); // Armazena o ID da página
        echo '<div class="updated"><p>Configurações atualizadas com sucesso!</p></div>';
    }

    // Obtém as configurações atuais
    $current_message = get_option('lgpd_consent_message', 'Este site usa cookies para melhorar sua experiência. Ao continuar navegando, você aceita os termos de privacidade.');
    $current_privacy_page = get_option('lgpd_privacy_page');

    // Formulário de configurações
    echo '<div class="wrap"><h1>Configurações de Consentimento</h1>';
    echo '<form method="post">';
    echo '<h2>Mensagem de Aceite</h2>';
    echo '<textarea name="lgpd_consent_message" rows="5" style="width: 100%;">' . esc_textarea($current_message) . '</textarea>';
    
    // Obtém as páginas publicadas
    $pages = get_pages();
    ?>

    <h2>Selecionar Política de Privacidade</h2>
    <select name="lgpd_privacy_page" style="width: 100%;">
        <option value="">Selecione uma página</option>
        <?php
        // Preenche o select com as páginas disponíveis
        foreach ($pages as $page) {
            $selected = ($current_privacy_page == $page->ID) ? 'selected' : '';
            echo '<option value="' . esc_attr($page->ID) . '" ' . $selected . '>' . esc_html($page->post_title) . '</option>';
        }
        ?>
    </select>

    <br><br><input type="submit" name="lgpd_update_settings" class="button button-primary" value="Salvar Configurações" />
    </form>
    </div>
    <?php
}

