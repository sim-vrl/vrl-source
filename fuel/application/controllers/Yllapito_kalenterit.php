<?php
class Yllapito_kalenterit extends CI_Controller
{
    
    private $allowed_user_groups = array('admin', 'jaos', 'jaos-yp', 'kisakalenteri');
    private $url;
    
    function __construct()
    {
        parent::__construct();
              
        $this->load->library('user_rights', array('groups' => $this->allowed_user_groups));
        if (!$this->user_rights->is_allowed()){       
            redirect($this->user_rights->redirect());
        }
       
       $this->load->model('Jaos_model');
       $this->load->model('Kisakeskus_model');

              $this->load->library('Kisajarjestelma');
              $this->load->library('Porrastetut');

        $this->url = "yllapito/kalenterit/";
        
    }
    
    private function _is_allowed_to_process_calendar($jaos, &$msg)	{
		//are you admin or editor?
        if(!($this->_is_jaos_admin() || ($this->Jaos_model->is_jaos_owner($this->ion_auth->user()->row()->tunnus, $jaos)))){
			$msg = "Jos et ole ylläpitäjä tai jaosvastaava, voit käsitellä vain omia jaoksiasi, tai niitä jaoksia joissa toimit kalenterityöntekijänä.";
			return false;
		}
		
		
		return true;		
		
	
    }
    
    private function _is_jaos_admin(){
        return $this->user_rights->is_allowed(array('admin', 'jaos'));
    }
    
    private function _is_jaos_owner($jaos){
        return $this->Jaos_model->is_jaos_owner($this->ion_auth->user()->row()->tunnus, $jaos, 1);
    }
    
    private function _jaos_options(){
        $jaos_options = array();
        if($this->_is_jaos_admin()){
            $jaos_options = $this->Jaos_model->get_jaos_option_list(false, false, false);
    
        }else {
            $jaoslist = $this->Jaos_model->get_users_jaos($this->ion_auth->user()->row()->tunnus);
            foreach ($jaoslist as $jaos){
                $jaos_options[$jaos['id']]=$jaos['lyhenne'];
            }
        }
        
        return $jaos_options;
    }
    public function pipari(){
		$this->fuel->pages->render('misc/pipari');
    }
    
    
////////////////////////////////////////////////////////////////////////////7
// PANEELI
////////////////////////////////////////////////////////////////////////////7

    public function index(){
        $data = array();
        $only_active = false;
        $skip_shows = false;
        $only_leveled = false;
        $data['jaokset'] = $this->Jaos_model->get_jaos_list($only_active, $only_leveled, $skip_shows);
        $data['url'] = $this->url;
        
        $applicationAmounts = $this->_getApplicationAmounts();
        $resultApplicationAmounts = $this->_getResultApplicationAmounts();


        foreach ($data['jaokset'] as &$jaos){
            
            $jaos['hakemukset_norm'] = $applicationAmounts[$jaos['id']]['kpl'] ?? 0;
            if($jaos['hakemukset_norm'] > 0){
                $jaos['hakemukset_norm' . '_latest'] = $applicationAmounts[$jaos['id']]['ilmoitettu'];
            }

            $jaos['tulokset_norm'] = $resultApplicationAmounts[$jaos['id']]['kpl'] ?? 0;
            if($jaos['tulokset_norm'] > 0){
                $jaos['tulokset_norm' . '_latest'] = $resultApplicationAmounts[$jaos['id']]['ilmoitettu'];
            }
            
           
        }
        
        $data['porrastetut_amount'] = $this->porrastetut->count_resultless_leveled_competitions();
    
    	$this->fuel->pages->render('yllapito/kisakalenteri/kisakalenterit_etusivu', $data);

    }
    
    private function _getApplicationAmounts(){
        $applicationAmounts = array();
        $result = $this->Kisakeskus_model->get_application_amounts_per_jaos(true);
        
        foreach ($result as $row){
            $applicationAmounts[$row['jaos']] = $row;
        }
        
        $result = $this->Kisakeskus_model->get_application_amounts_per_jaos(false);

        foreach ($result as $row){
            $applicationAmounts[$row['jaos']] = $row;
        }
        
        return $applicationAmounts;
        

    }
    
    private function _getResultApplicationAmounts(){
        $applicationAmounts = array();
        $result = $this->Kisakeskus_model->get_result_application_amounts_per_jaos(true);
        
        foreach ($result as $row){
            $applicationAmounts[$row['jaos']] = $row;
        }
        
        $result = $this->Kisakeskus_model->get_result_application_amounts_per_jaos(false);

        foreach ($result as $row){
            $applicationAmounts[$row['jaos']] = $row;
        }
        
        return $applicationAmounts;
        

    }
    
    
    
    
    public function porrastetut_run(){
        
        $done = $this->porrastetut->generate_results_automatically($max = 10);
        $kpl = $this->porrastetut->count_resultless_leveled_competitions();

        $this->fuel->pages->render('misc/naytaviesti', array('msg_type' => 'success', 'msg' => $done .' kpl porrastettuja kisoja arvottu. ' . $kpl . ' kpl jäljellä. Paina f5 jos haluat arpoa lisää.'));

    }

    ///////////////////////////////////////////////////////////////////////////
    // TYH-AJO (KERTALUONTOINEN, POISTUU AJON JÄLKEEN)
    //
    // Toimintaperiaate:
    //  1. ANALYYSI  - käydään kaikki hyväksytyt TYH-tulokset läpi erissä ja
    //                 kerätään laskennan tulos työtaulun. Ei koske oikeisiin
    //                 statistiikkoihin. Voi ajaa kuinka monta kertaa tahansa.
    //  2. ESIKATSELU- näytetään mitä muuttuisi (uudet / muuttuvat / ennallaan).
    //  3. PÄIVITYS  - tekee varmuuskopion TYH-riveistä, kirjoittaa ABSOLUUTTISET
    //                 arvot (ei lisää päälle -> ei tuplaannu vaikka ajaisi uudestaan).
    //  4. PALAUTUS  - palauttaa varmuuskopion ja ajaa uudelleen kopion jälkeen
    //                 hyväksytyt tulokset, ettei uusia hyväksyntöjä katoa.
    //
    // Vain ylläpitäjille (admin).
    ///////////////////////////////////////////////////////////////////////////

    const TYH_JAOS       = 8;
    const TYH_BATCH_SIZE = 100;
    const TYH_T_STATS    = 'vrlv3_hevosrekisteri_kisatiedot';
    const TYH_T_HORSES   = 'vrlv3_hevosrekisteri';
    const TYH_T_WORK     = 'vrlv3_tyh_ajo_work';
    const TYH_T_STATE    = 'vrlv3_tyh_ajo_state';
    const TYH_T_BACKUP   = 'vrlv3_tyh_ajo_backup';

    // ---- Sivu ---------------------------------------------------------------

    public function recalculate_tyh_stats() {
        if (!$this->ion_auth->is_admin()) {
            redirect($this->user_rights->redirect());
        }

        $vars = array();
        $s = self::TYH_T_STATS;

        try {
            $this->_tyh_ensure_tables();
            $st = $this->_tyh_state_get();

            $vars['st'] = $st;
            $vars['before_count'] = (int)$this->_tyh_q("SELECT COUNT(*) AS n FROM `$s` WHERE jaos = ?", array(self::TYH_JAOS))->row()->n;
            $vars['backup_exists'] = $this->_tyh_backup_exists();
            $vars['preview'] = (($st['a_status'] ?? '') === 'analysed') ? $this->_tyh_preview() : null;

            $vars['recent_stats_sample'] = $this->_tyh_q(
                "SELECT * FROM `$s` WHERE jaos = ? ORDER BY reknro ASC LIMIT 10", array(self::TYH_JAOS)
            )->result_array();
        } catch (Exception $e) {
            $vars['st'] = array();
            $vars['before_count'] = 0;
            $vars['backup_exists'] = false;
            $vars['preview'] = null;
            $vars['recent_stats_sample'] = array();
            $vars['msg'] = $e->getMessage();
            $vars['msg_type'] = 'error';
        }

        $vars['ajax_base']  = site_url('yllapito/tyh-ajo') . '/';
        $vars['csrf_name']  = $this->security->get_csrf_token_name();
        $vars['csrf_hash']  = $this->security->get_csrf_hash();
        $vars['jaos']       = self::TYH_JAOS;

        $this->fuel->pages->render('yllapito/tyh-ajo', $vars);
    }

    // ---- AJAX-toiminnot -----------------------------------------------------

    public function tyh_ajo_action($action = '') {
        if (!$this->ion_auth->is_admin()) {
            $this->_tyh_json(array('ok' => false, 'msg' => 'Vain ylläpitäjä voi ajaa tämän työkalun.'), 403);
        }
        if (strtoupper($this->input->server('REQUEST_METHOD')) !== 'POST') {
            $this->_tyh_json(array('ok' => false, 'msg' => 'Vain POST sallittu.'), 405);
        }

        $this->db->db_debug = FALSE; // virheet poikkeuksina, ei HTML-virhesivuna
        @set_time_limit(300);

        try {
            $this->_tyh_ensure_tables();
            $st = $this->_tyh_state_get();

            switch ($action) {

                // ---------- 1. Analyysi: alustus ----------
                case 'prepare':
                    if (($st['a_status'] ?? '') === 'analysing' && (int)($st['a_done'] ?? 0) > 0 && !$this->input->post('force')) {
                        throw new RuntimeException('Analyysi on jo kesken. Jatka sitä tai tyhjennä analyysi ensin.');
                    }
                    $cutoff = date('Y-m-d H:i:s'); // PHP:n kello, koska hyväksyntä kirjoittaa hyvaksytty-ajan PHP:llä
                    $w = self::TYH_T_WORK;
                    $this->_tyh_q("DELETE FROM `$w`");
                    $total = (int)$this->_tyh_q(
                        "SELECT COUNT(*) AS n" . $this->_tyh_results_from_where() . " AND t.hyvaksytty <= ?",
                        array(self::TYH_JAOS, $cutoff)
                    )->row()->n;

                    $this->_tyh_state_set('a_status', 'analysing');
                    $this->_tyh_state_set('a_cutoff', $cutoff);
                    $this->_tyh_state_set('a_last', 0);
                    $this->_tyh_state_set('a_done', 0);
                    $this->_tyh_state_set('a_total', $total);
                    $this->_tyh_json(array('ok' => true, 'total' => $total, 'done' => 0, 'last' => 0, 'finished' => ($total == 0)));
                    break;

                // ---------- 1b. Analyysi: yksi erä ----------
                case 'batch':
                    if (($st['a_status'] ?? '') !== 'analysing') {
                        throw new RuntimeException('Analyysi ei ole käynnissä.');
                    }
                    $after = (int)$this->input->post('after');
                    $last  = (int)($st['a_last'] ?? 0);
                    if ($after !== $last) {
                        throw new RuntimeException('Erän kursori ei täsmää (tuplaklikkaus tai toinen välilehti?). Lataa sivu uudelleen.');
                    }

                    $rows = $this->_tyh_q(
                        "SELECT t.tulos_id, t.kisa_id, t.luokat, t.tulokset, t.hylatyt, k.porrastettu"
                        . $this->_tyh_results_from_where()
                        . " AND t.hyvaksytty <= ? AND t.tulos_id > ? ORDER BY t.tulos_id ASC LIMIT " . (int)self::TYH_BATCH_SIZE,
                        array(self::TYH_JAOS, $st['a_cutoff'], $last)
                    )->result_array();

                    $total = (int)$st['a_total'];
                    $done  = (int)$st['a_done'];

                    if (empty($rows)) {
                        $this->_tyh_state_set('a_status', 'analysed');
                        $this->_tyh_json(array('ok' => true, 'finished' => true, 'total' => $total, 'done' => $done, 'last' => $last));
                    }

                    $ins = array();
                    foreach ($rows as $row) {
                        $porr  = !empty($row['porrastettu']);
                        $stats = $this->kisajarjestelma->calculate_result_stats($row, self::TYH_JAOS);
                        foreach ($stats as $vh => $c) {
                            $ins[] = array(
                                'reknro'   => $vh,
                                'voi'      => $porr ? 0 : $c['voi'],
                                'sij'      => $porr ? 0 : $c['sij'],
                                'os'       => $porr ? 0 : $c['os'],
                                'porr_voi' => $porr ? $c['voi'] : 0,
                                'porr_sij' => $porr ? $c['sij'] : 0,
                                'porr_os'  => $porr ? $c['os'] : 0,
                            );
                        }
                        $last = (int)$row['tulos_id'];
                    }
                    $done += count($rows);

                    // Erä + kursori samassa transaktiossa: jos jokin kaatuu, erän voi ajaa uudelleen ilman tuplauksia
                    $this->db->trans_begin();
                    try {
                        if (!empty($ins)) {
                            if ($this->db->insert_batch(self::TYH_T_WORK, $ins, NULL, 500) === FALSE) {
                                $err = $this->db->error();
                                throw new RuntimeException('Työtaulun kirjoitus epäonnistui: ' . ($err['message'] ?? ''));
                            }
                        }
                        $this->_tyh_state_set('a_last', $last);
                        $this->_tyh_state_set('a_done', $done);
                        $this->db->trans_commit();
                    } catch (Exception $e) {
                        $this->db->trans_rollback();
                        throw $e;
                    }

                    $this->_tyh_json(array('ok' => true, 'finished' => false, 'total' => $total, 'done' => $done, 'last' => $last));
                    break;

                // ---------- 3. Päivitys ----------
                case 'apply':
                    if (($st['a_status'] ?? '') !== 'analysed') {
                        throw new RuntimeException('Aja analyysi loppuun ennen päivitystä.');
                    }
                    if ($this->_tyh_backup_exists()) {
                        throw new RuntimeException('Varmuuskopio on jo olemassa. Palauta se tai hylkää se ennen uutta päivitystä.');
                    }
                    $s = self::TYH_T_STATS; $b = self::TYH_T_BACKUP; $h = self::TYH_T_HORSES;
                    $agg    = $this->_tyh_agg_sql();
                    $cutoff = $st['a_cutoff'];
                    $t1     = date('Y-m-d H:i:s');

                    // 3a. Varmuuskopio (DDL ei ole transaktiossa)
                    $this->_tyh_q("CREATE TABLE `$b` LIKE `$s`");
                    $this->_tyh_q("INSERT INTO `$b` SELECT * FROM `$s` WHERE jaos = ?", array(self::TYH_JAOS));
                    $expected = (int)$this->_tyh_q("SELECT COUNT(*) AS n FROM `$s` WHERE jaos = ?", array(self::TYH_JAOS))->row()->n;
                    $got      = (int)$this->_tyh_q("SELECT COUNT(*) AS n FROM `$b`")->row()->n;
                    if ($expected !== $got) {
                        $this->_tyh_q("DROP TABLE `$b`");
                        throw new RuntimeException("Varmuuskopion rivimäärä ei täsmää ($got / $expected). Mitään ei muutettu.");
                    }
                    $this->_tyh_state_set('snapshot_at', $t1);
                    $this->_tyh_state_set('backup_rows', $got);

                    // 3b. Absoluuttiset arvot
                    $this->db->trans_begin();
                    try {
                        $this->_tyh_q(
                            "UPDATE `$s` k JOIN $agg a ON a.reknro = k.reknro
                             SET k.voi = a.voi, k.sij = a.sij, k.os = a.os,
                                 k.porr_voi = a.porr_voi, k.porr_sij = a.porr_sij, k.porr_os = a.porr_os
                             WHERE k.jaos = ?", array(self::TYH_JAOS)
                        );
                        $updated = $this->db->affected_rows();

                        $this->_tyh_q(
                            "INSERT INTO `$s` (reknro, jaos, voi, sij, os, porr_voi, porr_sij, porr_os)
                             SELECT a.reknro, ?, a.voi, a.sij, a.os, a.porr_voi, a.porr_sij, a.porr_os
                             FROM $agg a
                             JOIN `$h` h ON h.reknro = a.reknro
                             LEFT JOIN `$s` k ON k.reknro = a.reknro AND k.jaos = ?
                             WHERE k.reknro IS NULL", array(self::TYH_JAOS, self::TYH_JAOS)
                        );
                        $inserted = $this->db->affected_rows();
                        $this->db->trans_commit();
                    } catch (Exception $e) {
                        $this->db->trans_rollback();
                        throw new RuntimeException($e->getMessage() . ' -- Muutokset peruttiin. Varmuuskopio on olemassa; hylkää se ennen uutta yritystä.');
                    }

                    // 3c. Analyysin alun ja päivityksen välissä hyväksytyt tulokset (ne on voitu laskea vanhoihin riveihin ja ylikirjoittaa)
                    $replayed = $this->_tyh_replay($cutoff, $t1);

                    $msg = "Päivitys valmis: $updated riviä päivitetty, $inserted uutta riviä lisätty, $replayed välissä hyväksyttyä tulosta ajettu uudelleen. Varmuuskopio: $got riviä.";
                    $this->_tyh_state_set('applied_at', date('Y-m-d H:i:s'));
                    $this->_tyh_state_set('last_msg', $msg);
                    $this->_tyh_state_set('a_status', 'applied');
                    log_message('error', '[TYH-ajo] ' . $msg . ' (käyttäjä ' . $this->ion_auth->user()->row()->tunnus . ')');
                    $this->_tyh_json(array('ok' => true, 'msg' => $msg));
                    break;

                // ---------- 4. Palautus ----------
                case 'revert':
                    if (!$this->_tyh_backup_exists() || empty($st['snapshot_at'])) {
                        throw new RuntimeException('Varmuuskopiota ei löydy.');
                    }
                    $s = self::TYH_T_STATS; $b = self::TYH_T_BACKUP;
                    $snapshot = $st['snapshot_at'];

                    $this->db->trans_begin();
                    try {
                        $this->_tyh_q("DELETE FROM `$s` WHERE jaos = ?", array(self::TYH_JAOS));
                        $this->_tyh_q("INSERT INTO `$s` SELECT * FROM `$b`");
                        $this->db->trans_commit();
                    } catch (Exception $e) {
                        $this->db->trans_rollback();
                        throw new RuntimeException($e->getMessage() . ' -- Palautus peruttiin, tila ennallaan.');
                    }

                    // Päivityksen jälkeen hyväksytyt tulokset olivat mukana vain uusissa luvuissa -> lisätään uudelleen
                    $replayed = $this->_tyh_replay($snapshot, null);

                    $msg = "Palautus valmis. Varmuuskopion " . (int)($st['backup_rows'] ?? 0) . " riviä palautettu, $replayed päivityksen jälkeen hyväksyttyä tulosta ajettu uudelleen.";
                    $this->_tyh_state_set('last_msg', $msg);
                    $this->_tyh_state_set('a_status', '');
                    log_message('error', '[TYH-ajo] ' . $msg . ' (käyttäjä ' . $this->ion_auth->user()->row()->tunnus . ')');
                    $this->_tyh_json(array('ok' => true, 'msg' => $msg));
                    break;

                // ---------- Siivous ----------
                case 'discard_backup':
                    $this->_tyh_q("DROP TABLE IF EXISTS `" . self::TYH_T_BACKUP . "`");
                    $this->_tyh_state_set('snapshot_at', '');
                    $this->_tyh_state_set('backup_rows', '');
                    $this->_tyh_state_set('last_msg', 'Varmuuskopio hylätty.');
                    $this->_tyh_json(array('ok' => true, 'msg' => 'Varmuuskopio hylätty.'));
                    break;

                case 'reset':
                    $this->_tyh_q("DELETE FROM `" . self::TYH_T_WORK . "`");
                    foreach (array('a_status', 'a_cutoff', 'a_last', 'a_done', 'a_total') as $k) {
                        $this->_tyh_state_set($k, '');
                    }
                    $this->_tyh_json(array('ok' => true, 'msg' => 'Analyysi tyhjennetty.'));
                    break;

                default:
                    throw new RuntimeException('Tuntematon toiminto.');
            }
        } catch (Exception $e) {
            log_message('error', '[TYH-ajo] ' . $action . ': ' . $e->getMessage());
            $this->_tyh_json(array('ok' => false, 'msg' => $e->getMessage()), 200);
        }
    }

    // ---- Apufunktiot --------------------------------------------------------

    private function _tyh_json($data, $status = 200) {
        $data['csrf_name'] = $this->security->get_csrf_token_name();
        $data['csrf_hash'] = $this->security->get_csrf_hash();
        $this->output
            ->set_status_header($status)
            ->set_content_type('application/json')
            ->set_output(json_encode($data));
        $this->output->_display();
        exit;
    }

    private function _tyh_q($sql, $bind = array()) {
        $res = $this->db->query($sql, $bind);
        if ($res === FALSE) {
            $err = $this->db->error();
            throw new RuntimeException('SQL-virhe: ' . ($err['message'] ?? 'tuntematon'));
        }
        return $res;
    }

    private function _tyh_ensure_tables() {
        $w = self::TYH_T_WORK; $t = self::TYH_T_STATE;
        $this->_tyh_q("CREATE TABLE IF NOT EXISTS `$w` (
            `reknro` BIGINT NOT NULL,
            `voi` INT NOT NULL DEFAULT 0, `sij` INT NOT NULL DEFAULT 0, `os` INT NOT NULL DEFAULT 0,
            `porr_voi` INT NOT NULL DEFAULT 0, `porr_sij` INT NOT NULL DEFAULT 0, `porr_os` INT NOT NULL DEFAULT 0,
            KEY `reknro` (`reknro`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        $this->_tyh_q("CREATE TABLE IF NOT EXISTS `$t` (
            `k` VARCHAR(40) NOT NULL, `v` VARCHAR(255) NOT NULL DEFAULT '', PRIMARY KEY (`k`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    }

    private function _tyh_state_get() {
        $out = array();
        foreach ($this->_tyh_q("SELECT k, v FROM `" . self::TYH_T_STATE . "`")->result_array() as $r) {
            $out[$r['k']] = $r['v'];
        }
        return $out;
    }

    private function _tyh_state_set($k, $v) {
        $this->_tyh_q("REPLACE INTO `" . self::TYH_T_STATE . "` (k, v) VALUES (?, ?)", array($k, (string)$v));
    }

    private function _tyh_backup_exists() {
        return $this->_tyh_q("SHOW TABLES LIKE '" . self::TYH_T_BACKUP . "'")->num_rows() > 0;
    }

    // Hyväksytyt TYH-tulokset (sama ehto kuin tulosten poistossa: ei NULL eikä nollapäivä)
    private function _tyh_results_from_where() {
        return " FROM vrlv3_kisat_tulokset t
                 JOIN vrlv3_kisat_kisakalenteri k ON k.kisa_id = t.kisa_id
                 WHERE k.jaos = ? AND t.hyvaksytty IS NOT NULL AND t.hyvaksytty != '0000-00-00 00:00:00'";
    }

    // Työtaulun rivit summattuna hevosittain
    private function _tyh_agg_sql() {
        $w = self::TYH_T_WORK;
        return "(SELECT reknro, SUM(voi) AS voi, SUM(sij) AS sij, SUM(os) AS os,
                        SUM(porr_voi) AS porr_voi, SUM(porr_sij) AS porr_sij, SUM(porr_os) AS porr_os
                 FROM `$w` GROUP BY reknro)";
    }

    // Ajaa add_stats() tuloksille jotka on hyväksytty välillä ($from, $to]. $to = null -> ei ylärajaa.
    private function _tyh_replay($from, $to = null) {
        $sql  = "SELECT t.tulos_id, t.kisa_id, t.luokat, t.tulokset, t.hylatyt, k.porrastettu"
              . $this->_tyh_results_from_where() . " AND t.hyvaksytty > ?";
        $bind = array(self::TYH_JAOS, $from);
        if ($to !== null) {
            $sql .= " AND t.hyvaksytty <= ?";
            $bind[] = $to;
        }
        $rows = $this->_tyh_q($sql . " ORDER BY t.tulos_id ASC", $bind)->result_array();
        foreach ($rows as $row) {
            $this->kisajarjestelma->add_stats($row, self::TYH_JAOS, $row['porrastettu'] ?? 0);
        }
        return count($rows);
    }

    // Esikatselu: mitä päivitys tekisi nykytilaan nähden
    private function _tyh_preview() {
        $s = self::TYH_T_STATS; $h = self::TYH_T_HORSES;
        $agg = $this->_tyh_agg_sql();
        $j   = self::TYH_JAOS;
        $same = "k.voi <=> a.voi AND k.sij <=> a.sij AND k.os <=> a.os
                 AND k.porr_voi <=> a.porr_voi AND k.porr_sij <=> a.porr_sij AND k.porr_os <=> a.porr_os";
        $n = function ($sql, $bind = array()) { return (int)$this->_tyh_q($sql, $bind)->row()->n; };

        $p = array();
        $p['horses']    = $n("SELECT COUNT(*) AS n FROM $agg a");
        $p['unknown']   = $n("SELECT COUNT(*) AS n FROM $agg a LEFT JOIN `$h` h ON h.reknro = a.reknro WHERE h.reknro IS NULL");
        $p['new']       = $n("SELECT COUNT(*) AS n FROM $agg a JOIN `$h` h ON h.reknro = a.reknro
                              LEFT JOIN `$s` k ON k.reknro = a.reknro AND k.jaos = ? WHERE k.reknro IS NULL", array($j));
        $p['matched']   = $n("SELECT COUNT(*) AS n FROM $agg a JOIN `$s` k ON k.reknro = a.reknro AND k.jaos = ?", array($j));
        $p['changed']   = $n("SELECT COUNT(*) AS n FROM $agg a JOIN `$s` k ON k.reknro = a.reknro AND k.jaos = ?
                              WHERE NOT ($same)", array($j));
        $p['unchanged'] = $p['matched'] - $p['changed'];
        $p['untouched'] = $n("SELECT COUNT(*) AS n FROM `$s` k LEFT JOIN $agg a ON a.reknro = k.reknro
                              WHERE k.jaos = ? AND a.reknro IS NULL", array($j));
        $p['sample']    = $this->_tyh_q(
            "SELECT a.reknro,
                    k.os AS old_os, k.sij AS old_sij, k.voi AS old_voi,
                    a.os AS new_os, a.sij AS new_sij, a.voi AS new_voi,
                    k.porr_os AS old_porr_os, a.porr_os AS new_porr_os
             FROM $agg a JOIN `$s` k ON k.reknro = a.reknro AND k.jaos = ?
             WHERE NOT ($same)
             ORDER BY ABS(a.os - IFNULL(k.os, 0)) DESC LIMIT 10", array($j)
        )->result_array();
        return $p;
    }


//////////////////////////////////////////////////////////////////////////////////////////
// Käsittele etuuspisteet
/////////////////////////////////////////////////////////////////////////////////////////

public function etuuspisteet($jaos = null, $tunnus = null){
        $data = array();
        $msg = "";
              
        if(isset($jaos) && isset($tunnus)){
            if($this->_is_allowed_to_process_calendar($jaos, $msg)){
                $jaos_data = $this->Jaos_model->get_jaos($jaos);
                $user_data = $this->Jaos_model->getEtuuspisteet($jaos, $tunnus);
                IF($this->input->server('REQUEST_METHOD') == 'POST' && $this->input->post('tunnus') == null){
                    $edit_data = array();
                    
                    if($this->input->post('nollaa') == 1){
                        $edit_data['pisteet'] = 0;
                        $edit_data['nollattu'] = 1;
                       
                    }else if( $this->input->post('pisteet') != null
                             && strlen($this->input->post('pisteet')) > 0
                           && is_numeric($this->input->post('pisteet'))
                           && $this->input->post('pisteet') > 0){
                        $edit_data['nollattu'] = 0;
                        $edit_data['pisteet'] = $this->input->post('pisteet');
                        
                    }else {
                        $data = array('msg_type' => 'danger', 'msg' => "Virheellinen syöte!");

                    }
                        
                    if(sizeof($edit_data)> 0){
                        $edit_data['muokattu'] = date("Y-m-d");
                        $edit_data['muokkaaja'] = $this->ion_auth->user()->row()->tunnus;

                        $data = array('msg_type' => 'success', 'msg' => "Muokkaus onnistui!");
                        if(sizeof($user_data) > 0){
                        $this->db->where('tunnus', $this->vrl_helper->vrl_to_number($tunnus));
                        $this->db->where('jaos', $jaos);
                        $this->db->update('vrlv3_kisat_etuuspisteet', $edit_data);
                        }else {
                            $edit_data['tunnus'] = $this->vrl_helper->vrl_to_number($tunnus);
                            $edit_data['jaos'] = $jaos;
                            $this->db->insert('vrlv3_kisat_etuuspisteet', $edit_data);
                        }
                        
                        $this->load->model('Tunnukset_model');
                        $pisteet = $user_data['pisteet'] ?? 0;
                        $this->Tunnukset_model->send_message($this->ion_auth->user()->row()->tunnus, $this->vrl_helper->vrl_to_number($tunnus),
                                                        "Etuuspisteitäsi on muokattu (Jaos: " . $jaos_data['nimi'] . ", vanha arvo: ".floatval($pisteet).",
                                                        uusi arvo: ".$edit_data['pisteet']."). Jos et tiedä miksi, ole yhteydessä jaoksen ylläpitoon!");

                        
                        $user_data =  $this->Jaos_model->getEtuuspisteet($jaos, $tunnus);
         

                    }
                }
                    
                    
                    
                $this->load->library('form_builder', array('submit_value' => "Tallenna", 'required_text' => '*Pakollinen kenttä'));
                $fields['nollaa'] = array('label'=>'Nollaa etuuspisteet', 'type' => 'checkbox', 'checked' => false, 'class'=>'form-control');
                $fields['pisteet'] = array('label' => 'Etuuspisteet', 'type' => 'text',
                                                 'value' => $user_data['pisteet'] ?? 0, 'class'=>'form-control');
                if(sizeof($user_data) > 0 && $user_data['muokattu'] != null && $user_data['muokattu'] != '0000-00-00 00:00:00'){
                    $nollaus = "";
                    if($user_data['nollattu'] == 1){
                        $nollaus = "Etuuspisteet nollattiin.";
                    }
                    $fields['section_example'] = array('type' => 'section', 'tag' => 'h3',
                                                       'value' => 'Viimeisin muutos ' . $this->vrl_helper->sql_date_to_normal($user_data['muokattu']) .
                                                       ', muokkaaja: VRL-'.$user_data['muokkaaja'].'. ' . $nollaus,
                                                       'after_html'=>'<span class="form_comment">Tässä näkyy tieto viimeisestä manuaalisesta muokkauksesta!</span>');
 
                }
                   
                
                $this->form_builder->form_attrs = array('method' => 'post', 'action' => site_url($this->url.'etuuspisteet/'.$jaos."/".$tunnus));
                $data['form'] = $this->form_builder->render_template('_layouts/basic_form_template', $fields);
                $data['title'] = 'Etuuspisteet (Käyttäjä: <a href="'.site_url().'tunnus/'. $tunnus .'">VRL-'.$tunnus.'</a>, jaos: '.$jaos_data['lyhenne'].')';
                
                $this->fuel->pages->render('misc/haku', $data);
                                            
                    

                
            }else {
                $this->fuel->pages->render('misc/naytaviesti', array('msg_type' => 'danger', 'msg' => "Sinulla ei ole oikeuksia käsitellä valitsemaasi jaosta."));

            }
      
        } else IF ($this->input->server('REQUEST_METHOD') == 'POST'){
            if($this->vrl_helper->check_vrl_syntax($this->input->post('tunnus'))
               && $this->input->post('jaos') !== false
               && strlen($this->input->post('tunnus')) > 0){
                $this->etuuspisteet($this->input->post('jaos'), $this->input->post('tunnus'));
            }else {
                $this->fuel->pages->render('misc/naytaviesti', array('msg_type' => 'danger', 'msg' => "Virheellinen VRL-tunnus."));

            }
        }else {
                $this->load->library('form_builder', array('submit_value' => "Hae", 'required_text' => '*Pakollinen kenttä'));
                $this->form_builder->form_attrs = array('method' => 'post', 'action' => site_url($this->url.'etuuspisteet'));
                $jaos_options = $this->_jaos_options();
                unset($jaos_options[-1]);
                $fields['jaos'] = array('type' => 'select', 'required'=> TRUE, 'options' => $jaos_options, 'value' => $data['jaos'] ?? "1", 'class'=>'form-control');
                $fields['tunnus'] = array('type' => 'text', 'label' => "VRL-tunnus", 'required' => TRUE, 'class'=>'form-control');                  
                $data['form'] =  $this->form_builder->render_template('_layouts/basic_form_template', $fields);
                $data['title'] = "Hae käyttäjän etuuspisteeet";
                 $this->fuel->pages->render('misc/haku', $data);
                
        }
            
              

    }
   
    
   
    
////////////////////////////////////////////////////////////////////////////////////////77
// Käsittele hyväksyttyjä
/////////////////////////////////////////////////////////////////////////////////////////
    
    public function hyvaksytytkisat($tapa = null, $type = null, $id = null){
        if($tapa == "delete"){
            $msg = "";
            if($this->_delete_competition($id, $type, $msg)){
                $this->fuel->pages->render('misc/naytaviesti', array('msg_type' => 'success', 'msg' => 'Kilpailu #'.$id.' poistettu!'));
            }else {
                $this->fuel->pages->render('misc/naytaviesti', array('msg_type' => 'danger', 'msg' => 'Kilpailu #'.$id.' poisto epäonnistui! ' . $msg));
            }
        }else if($tapa == "edit"){
            $data = array();
            if($this->_edit_competition($id, $type, $msg, $data)){
                

                $this->fuel->pages->render('misc/haku', $data);
                
            }else {
                $data['msg_type'] = 'danger';
                $data['msg'] = 'Kilpailu #'.$id.' muokkaus epäonnistui! ' . $msg;
                if(isset($data['form'])){
                    $this->fuel->pages->render('misc/haku', $data);
                }
                
                else {
                    $this->fuel->pages->render('misc/naytaviesti', $data);

                }
            }

            
        }else {      
        
            $data = $this->_hyvaksytyt_selaa(false, true, $this->url."hyvaksytytkisat");
            $data['title'] = "Kilpailukalenteri: Hyväksytyt kilpailut";
            $data['text_view'] = "Hakutuloksina näytetään 1000 viimeksi hyväksyttyä hakukriteereihin sopivaa kilpailua.";
    
            $this->fuel->pages->render('misc/haku', $data);
        }

    }
    
    private function _edit_competition($id, $type, &$msg = "", &$data = array()){
        $nayttelyt = false;
        $ok = true;

        if($type == "nayttelyt"){
               $nayttelyt = true;
           }
           
        $porrastettu = false;

        $kutsu = $this->Kisakeskus_model->hae_kutsutiedot($id, null, 0, $nayttelyt);
        if(!$nayttelyt){
            $porrastettu = $kutsu['porrastettu'];
        }
        if(sizeof($kutsu)>0){
            $jaos = $kutsu['jaos'];
             if($this->_is_jaos_owner($jaos) || $this->_is_jaos_admin()){
                 if($this->input->server('REQUEST_METHOD') == 'POST'){
                    
                     $kutsu_new = $this->kisajarjestelma->parse_competition_application(false);
                     if(!$this->kisajarjestelma->validate_competition_application($porrastettu, $nayttelyt, false)){
                             $msg = "Virhe syötetyissä tiedoissa.";
                             $ok = false;
                             
                     }else if(!$this->kisajarjestelma->check_competition_edit_info(array_merge($kutsu, $kutsu_new), $msg)){
                         $ok = false;
                     }else if (!$this->kisajarjestelma->edit_competition($id, $jaos, $kutsu_new, $msg)){
                        $ok = false;
                     } else {
                            $data['msg_type'] = 'success';
                            $data['msg'] = 'Kilpailu #'.$id.' muokattu!';
                     }
                     
                     $kutsu = array_merge($kutsu, $kutsu_new);
                     
                 
                 }
             
                 
                 $data['form'] = $this->kisajarjestelma->get_competition_application ("edit", $this->url."hyvaksytytkisat/edit/".$type."/".$kutsu['kisa_id'],
                                                                                      $porrastettu, $nayttelyt, $kutsu, $kutsu['jaos']);           
                 $data['title'] = "Muokkaa kilpailukutsua (#".$kutsu['kisa_id'];
                 if($porrastettu){
                     $data['title'] .= ", porrastettu)";
                 }else {
                     $data['title'] .= ")";
                 }
                 
             }else {
                 $msg = 'Vain ylläpitäjillä, jaosvastaavalla tai jaoksen ylläpitäkällä on oikeus muokata kutsuja.';
                 $ok = false;
 
             }
         }else {
             $msg = 'Kutsua ei löydy, tai sillä on jo hyväksytyt tai ilmoitetut tulokset!';
             $ok = false;

         }
         return $ok;
    }
    

    
    public function hyvaksytyttulokset($tapa = null, $type = null, $id = null){
        if($tapa == "delete"){
            $msg = "";
            if($this->_delete_result($id, $type, $msg)){
                $this->fuel->pages->render('misc/naytaviesti', array('msg_type' => 'success', 'msg' => 'Tulos #'.$id.' poistettu!'));
            }else {
                $this->fuel->pages->render('misc/naytaviesti', array('msg_type' => 'danger', 'msg' => 'Tuloksen #'.$id.' poisto epäonnistui! ' . $msg));
            }
        }else {      
        
            $data = $this->_hyvaksytyt_selaa(true, false, $this->url."hyvaksytyttulokset");
            $data['title'] = "Kilpailukalenteri: Hyväksytyt tulokset";
            $data['text_view'] = "Hakutuloksina näytetään 1000 viimeksi hyväksyttyä hakukriteereihin sopivaa tulosta.";
    
            $this->fuel->pages->render('misc/haku', $data);
        }
    }
    
    private function _hyvaksytyt_selaa($result, $competition, $url){
        $data = array();
        $nayttelyt = false;
        $data['search'] = array();
        $data['kisat'] = array();
        
        if($this->input->server('REQUEST_METHOD') == 'POST'){
            $data['search'] = $this->_read_search_form();
            if (!isset($data['search']['jaos'])){
             $data['msg'] = "Jaos on pakollinen hakukriteeri. ";
             $data['msg_type'] = "danger";
            }
            else if (!$this->_is_allowed_to_process_calendar($data['search']['jaos'], $msg)){
             $data['msg'] = "Sinulla ei ole oikeutta valitun jaoksen kilpailukalenteriin. " . $msg;
             $data['msg_type'] = "danger";
            }else {
                $nayttelyt = $this->kisajarjestelma->nayttelyjaos($data['search']['jaos']);
                if($result){
                    $data['kisat'] = $this->Kisakeskus_model->search_results($data['search']['jaos'], $data['search'], $nayttelyt);
                }if($competition){
                    $data['kisat'] = $this->Kisakeskus_model->search_competitions($data['search']['jaos'], $data['search'], $nayttelyt);
                }
            }
            
        }
        
        $data['tulokset'] = $this->kisajarjestelma->competition_result_search_result_list($result, $competition, $nayttelyt , $url, true, $data['kisat']);
        $data['form'] = $this->_search_form($result, $competition, $url,  $data['search']);
        return $data;
    }
    
    
    
    private function _search_form($result, $competition, $url, $data = array()){
        return $this->kisajarjestelma->competition_result_search_form($result, $competition, $url, true, $this->_jaos_options(), $data);

    }

    private function _read_search_form(){
       return $this->kisajarjestelma->read_result_competition_search_form();
    }
    
    private function _read_basic_input_field(&$data, $field){
            if($this->input->post($field)){
            $data[$field] = $this->input->post($field);
        }
        
    }



 
    private function _delete_competition($kisa_id, $type, &$msg){
        $ok = true;
        $db  = 'vrlv3_kisat_kisakalenteri';
        $nayttelyt = false;
        if($type == "nayttelyt"){
            $db = 'vrlv3_kisat_nayttelykalenteri';
            $nayttelyt = true;
        }
        
        $this->db->trans_start();
                
        $this->db->select('*');
        $this->db->from($db.' as k');
        $this->db->where('kisa_id', $kisa_id);
        $this->db->where('tulokset', 0);
        $this->db->where('k.hyvaksytty is NOT NULL', NULL, FALSE);
                
        $query = $this->db->get();
        
        if ($query->num_rows() > 0)
        {
            $id = $query->result_array()[0]['kisa_id'];
            $jaos  = $query->result_array()[0]['jaos'];
            $item = "Kilpailukutsu";
            if($nayttelyt){
                $item = "Näyttelykutsu";
            }
            if($this->_is_jaos_owner($jaos) || $this->_is_jaos_admin()){
                $this->db->delete($db, array('jaos'=>$jaos, 'kisa_id'=>$id));
                $this->load->model('Tunnukset_model');
                $this->Tunnukset_model->send_message($this->ion_auth->user()->row()->tunnus, $query->result_array()[0]['tunnus'] ,
                                                        $item. " #".$query->result_array()[0]['kisa_id']." on poistettu kalenterista! Jos et tiedä miksi, ole yhteydessä jaoksen ylläpitoon!");

                $this->db->trans_complete();
                
                if ($this->db->trans_status() === FALSE)
                {
                    $msg = "Virhe kutsun #".$id." poistossa. Yritä hetken kuluttua uudelleen, tai ole yhteydessä ylläpitoon.";        
                     $ok = false;
                }

            }else {
              $this->db->trans_complete();
              $msg = "Vain jaosvastaavalla ja jaoksen ylläpitäjällä on oikeus poistaa kilpailuja kalenterista";
              $ok = false;
            }

        } else {
              $this->db->trans_complete();
              $msg = "Kilpailua ei löytynyt, tai siitä on jo ilmoitettu tulokset.";
              $ok = false;
        }
        
        return $ok;

    }
    
    
    
    
    
    private function _delete_result($result_id, $type, &$msg){
        $ok = true;
        
        $db_kisa  = 'vrlv3_kisat_kisakalenteri';
        $db_tulos  = 'vrlv3_kisat_tulokset';
        $db_kisa_id_ref = "kisa_id";
        $db_res_id = "tulos_id";
        $nayttelyt = false;
        if($type == "nayttelyt"){
            $db_kisa = 'vrlv3_kisat_nayttelykalenteri';
            $db_tulos = 'vrlv3_kisat_nayttelytulokset';
            $db_kisa_id_ref = "nayttely_id";
            $db_res_id = "bis_id";

            $nayttelyt = true;
        }
        $this->db->trans_start();
                
        $this->db->select('*, t.'.$db_kisa_id_ref.' as kisa_id, t.hyvaksytty as hyvaksytty');
        $this->db->from($db_tulos.' as t');
        $this->db->join($db_kisa. ' as k', 'k.kisa_id = t.'. $db_kisa_id_ref);
        $this->db->where('t.'. $db_res_id, $result_id);
        $this->db->where('t.hyvaksytty is NOT NULL', NULL, FALSE);
        $this->db->where('t.hyvaksytty !=','0000-00-00 00:00:00');

                
        $query = $this->db->get();
        
        if ($query->num_rows() > 0)
        {
            $id = $query->result_array()[0][$db_res_id];
            $jaos = $query->result_array()[0]['jaos'];
            
            $jaosvastaava = $this->_is_jaos_admin();
            $jaosyp = $this->_is_jaos_owner($jaos);
            
            $hyvaksytty = new DateTime($query->result_array()[0]['hyvaksytty']);
            $date = new DateTime();
            $allowed_time = $this->kisajarjestelma->result_allowed_delete_time($jaosvastaava, $jaosyp);
            $date->setTimestamp(time() - $allowed_time);
            $oldest_possible = $date->format('Y-m-d H:i:s');
            
            if(!($jaosvastaava || $jaosyp)){
              $this->db->trans_complete();
              $msg = "Vain jaosvastaavalla ja jaoksen ylläpitäjällä on oikeus poistaa tuloksia kalenterista";
              $ok = false;
            }
            else if(!$this->ion_auth->is_admin() && $hyvaksytty < $oldest_possible){
                $this->db->trans_complete();
                $msg = "Tulos on liian vanha poistettavaksi. Jaoksen ylläpitäjällä on oikeus poistaa kalenterista tuloksia vain " .  $this->kisajarjestelma->result_allowed_delete_time(false, true)/60/60 . " tunnin kuluessa hyväksynnästä.
                Jaosvastaava voi poistaa tuloksia " . $this->kisajarjestelma->result_allowed_delete_time(true)/60/60/24 . " päivän kuluessa hyväksynnästä.
                VRL:n ylläpito voi poistaa tuloksia rajatta. Otathan yhteyttä tukeen jos oikeutesi eivät riitä!";
                $ok = false;
            }
            else if($this->_is_jaos_owner($jaos) || $this->_is_jaos_admin()){
                $this->db->delete($db_tulos, array($db_res_id=>$id));
                
                $this->db->where('kisa_id', $query->result_array()[0]['kisa_id']);
                $this->db->update($db_kisa, array('tulokset'=> 0));
                
                $this->load->model('Tunnukset_model');
                $item = "Kilpailun";
                if($nayttelyt){
                    $item = "Näyttelyn";
                }
                $this->Tunnukset_model->send_message($this->ion_auth->user()->row()->tunnus, $query->result_array()[0]['tunnus'] ,
                                                         $item." #".$query->result_array()[0]['kisa_id']." tulokset on poistettu arkistosta! Jos et tiedä miksi, ole yhteydessä jaoksen ylläpitoon!");

                $this->db->trans_complete();
                
                if ($this->db->trans_status() === FALSE)
                {
                    $msg = "Virhe tuloksen #".$id." poistossa. Yritä hetken kuluttua uudelleen, tai ole yhteydessä ylläpitoon.";        
                     $ok = false;
                }

            }

        } else {
              $this->db->trans_complete();
              $msg = "Tulosta ei löytynyt.";
              $ok = false;
        }
        
        return $ok;

    }
	
    
    

	
   
   ///////////////////////////////////////////////////////////////////////////////
    // HYVÄKSYNNÄT: Kisakalenterin ylläpitofunktiot
    //////////////////////////////////////////////////////////////////////////////
    
   
    public function kisahyvaksynta ($jaos = null, $kasittele = null, $kisa_id = null){
        $data=array();
        $this->load->model('Tunnukset_model');
        $jaos_data = $this->Jaos_model->get_jaos($jaos);
        $msg = "";
        
        if(empty($jaos)){
$this->index();

        
        } 
        
        else if(sizeof($jaos_data) == 0){
            $data['msg'] = "Hakemaasi Jaosta (".$jaos.") ei ole olemassa.";
            $data['msg_type'] = "danger";
                        $this->fuel->pages->render('misc/naytaviesti', array($data));

        }else if (!$this->_is_allowed_to_process_calendar($jaos, $msg)){
             $data['msg'] = "Sinulla ei ole oikeutta jaoksen ".$jaos_data['lyhenne']." kilpailukalenteriin. " . $msg;
            $data['msg_type'] = "danger";
                        $this->fuel->pages->render('misc/naytaviesti', array($data));

        }else {

            if(isset($kasittele) && $kasittele == 'hyvaksy'){
                if($this->_approve_competition($jaos, $kisa_id, true)){
                    $data['msg'] = "Kutsu hyväksytty.";
                    $data['msg_type'] = "success";
                }else {
                    $data['msg'] = "Kutsua #".$kisa_id." ei enää löydy, tai se on jonkun toisen käsiteltävänä.";
                    $data['msg_type'] = 'danger';
    
                }
                
            }else if( isset($kasittele) && $kasittele == 'hylkaa'){
                if($this->_approve_competition($jaos, $kisa_id, false, $this->input->post("viesti", TRUE))){
                    $data['msg'] = "Kutsu hylätty.";
                    $data['msg_type'] = "success";
                }else {
                    $data['msg'] = "Kutsua #".$kisa_id." ei enää löydy, tai se on jonkun toisen käsiteltävänä.";
                    $data['msg_type'] = 'danger';
    
                }
            }
            
            $data['kutsu'] = $this->_competitions_queue_get_next($jaos, false);
            
            if(isset($data['kutsu']) &&  sizeof($data['kutsu']) > 0){
                $this->load->model('Sport_model');
                $data['kutsu']['laji'] = $this->Sport_model->get_sport_info($data['kutsu']['laji'])['painotus'];
                $this->load->model("Tallit_model");
                $data['talli'] = $this->Tallit_model->get_stable($data['kutsu']['jarj_talli']);
                $data['jaos'] = $jaos_data;
                $data['kutsu']['arvontatapa'] = $this->kisajarjestelma->arvontatavat_options_legacy()[$data['kutsu']['arvontatapa']];
                $user = $this->ion_auth->user($this->Tunnukset_model->get_users_id($data['kutsu']['tunnus']))->row();
                $data['username'] = $user->nimimerkki;
                $data['user_email'] = $user->email;
                $data['user_vrl'] = $this->vrl_helper->get_vrl($user->tunnus);
            }
            
            $this->fuel->pages->render('yllapito/kisakalenteri/kisahyvaksynta', $data);

            

        }
        
        


        
    }
    

    public function tuloshyvaksynta ($jaos = null, $kasittele = null, $kisa_id = null){
        $data=array();
        $this->load->model('Tunnukset_model');
        $jaos_data = $this->Jaos_model->get_jaos($jaos);
        $msg = "";
        
        if(empty($jaos)){
            $this->index();
        } 
        
        else if(sizeof($jaos_data) == 0){
            $data['msg'] = "Hakemaasi Jaosta (".$jaos.") ei ole olemassa.";
            $data['msg_type'] = "danger";
            $this->fuel->pages->render('misc/naytaviesti', array($data));

        }else if (!$this->_is_allowed_to_process_calendar($jaos, $msg)){
             $data['msg'] = "Sinulla ei ole oikeutta jaoksen ".$jaos_data['lyhenne']." kilpailukalenteriin. " . $msg;
            $data['msg_type'] = "danger";
                        $this->fuel->pages->render('misc/naytaviesti', array($data));

        }else {

            if(isset($kasittele) && $kasittele == 'hyvaksy'){
                if($this->_approve_result($jaos, $kisa_id, true)){
                    $data['msg'] = "Kisan #" . $kisa_id ." tulokset hyväksytty.";
                    $data['msg_type'] = "success";
                }else {
                    $data['msg'] = "Tulosta jota hait ei enää löydy, tai se on jonkun toisen käsiteltävänä.";
                    $data['msg_type'] = 'danger';
                    
    
                }
                
            }else if( isset($kasittele) && $kasittele == 'hylkaa'){
                if($this->_approve_result($jaos, $kisa_id, false, $this->input->post("viesti", TRUE))){
                    $data['msg'] = "Tulos hylätty.";
                    $data['msg_type'] = "success";
                }else {
                    $data['msg'] = "Tulosta jota hait ei enää löydy, tai se on jonkun toisen käsiteltävänä.";
                    $data['msg_type'] = 'danger';
    
                }
            }
            
            $tulos_info = $this->_results_queue_get_next($jaos);
            
            if(isset($tulos_info) &&  sizeof($tulos_info) > 0){
                $tulos_info['jaos_info'] = $this->Jaos_model->get_jaos($tulos_info['jaos']);
                $this->load->model('Tallit_model');
                $tulos_info['talli_info'] = $this->Tallit_model->get_stable($tulos_info['jarj_talli']);
                $this->load->library('Kisajarjestelma');
                $this->load->model("Tunnukset_model");
                $tulos_info['tunnus'] =  $this->ion_auth->user($this->Tunnukset_model->get_users_id($tulos_info['tunnus']))->row();
                $tulos_info['tulosten_lah'] = $this->ion_auth->user($this->Tunnukset_model->get_users_id($tulos_info['tulosten_lah']))->row();
                if(isset($tulos_info['takaaja']) && $tulos_info['takaaja'] != 00000){
                    $tulos_info['takaaja'] =  $this->ion_auth->user($this->Tunnukset_model->get_users_id($tulos_info['takaaja']))->row();
                }
                unset($tulos_info['hyvaksyi']);
                $tulos_info['jarjestelma']= & $this->kisajarjestelma;
                
                $nayttelyt =  $tulos_info['jaos_info']['nayttelyt'];                
                
                
                 if($nayttelyt){
                    $tulos_info['tulos_id'] = $tulos_info['bis_id'];
                    $tulos_info['kisa_id'] = $tulos_info['nayttely_id'];
                    $tulos_info['porrastettu'] = false;
                    
                }
                $data['tulos'] = $tulos_info;
                $data['tulos_info'] = $this->load->view('kilpailutoiminta/tulos_info', array("tulos" => $data['tulos']), TRUE);
                $luokat = "";
                if($nayttelyt){
                    
                    $bis_rivit = $this->Kisakeskus_model->get_showresult_rewards($tulos_info['bis_id']);
                    $taulu['headers'][1] = array('title' => 'Palkinto', 'key' => 'palkinto');
                    $taulu['headers'][2] = array('title' => 'Hevonen', 'key' => 'vh_nimi');
                    $taulu['headers'][3] = array('title' => 'Reknro', 'key' => 'vh_id', 'type'=>'VH', 'key_link' => site_url('virtuaalihevoset/hevonen/'));        
                    $taulu['headers'] = json_encode($taulu['headers']);
                            
                    $taulu['data'] = json_encode($bis_rivit);
                    $bis_tulokset = $this->load->view('misc/taulukko', $taulu, TRUE);
                     $data['luokat_info'] = $this->load->view('kilpailutoiminta/tulos_nayttelyt', array("tulokset" => $tulos_info, "bistulokset"=>$bis_rivit, "bistaulu"=>$bis_tulokset), TRUE);

                    

                }else {
                    
                    
                     $data['luokat_info'] = $this->load->view('kilpailutoiminta/tulos_luokat', array("tulos" => $tulos_info), TRUE);

                }
                
          
            }
            
            
            
            
            $this->fuel->pages->render('yllapito/kisakalenteri/tuloshyvaksynta', $data);
        }
        

        
    }

    
    
    private function _competitions_queue_get_next($jaos, $porrastettu = false){
        if($this->kisajarjestelma->nayttelyjaos($jaos)){
            $this->db->from('vrlv3_kisat_nayttelykalenteri as k');
            return $this->_get_next('vrlv3_kisat_nayttelykalenteri', $jaos);


        } else {
            $this->db->from('vrlv3_kisat_kisakalenteri as k');
            $this->db->where('k.porrastettu', $porrastettu);
            return $this->_get_next('vrlv3_kisat_kisakalenteri', $jaos);
        }


    }
    
    private function _results_queue_get_next($jaos, $porrastettu = false){
        if($this->kisajarjestelma->nayttelyjaos($jaos)){
            $this->db->select('t.*, k.kp, k.vip, t.ilmoitettu, k.tunnus as tunnus, k.url, t.tunnus as tulosten_lah, k.jarj_talli, k.info, k.tunnus, k.jaos, k.arvontatapa');
            $this->db->from('vrlv3_kisat_nayttelykalenteri as k');
            $this->db->join('vrlv3_kisat_nayttelytulokset as t', 'k.kisa_id = t.nayttely_id');
          return $this->_get_next('vrlv3_kisat_nayttelytulokset', $jaos);
        }
        
        else {
            $this->db->select('t.*, k.kp, k.vip, t.ilmoitettu, k.tunnus as tunnus, k.url, k.porrastettu, t.tunnus as tulosten_lah, k.jarj_talli, k.info, k.tunnus, k.jaos, k.arvontatapa');
            $this->db->from('vrlv3_kisat_kisakalenteri as k');
            $this->db->join('vrlv3_kisat_tulokset as t', 'k.kisa_id = t.kisa_id');
          return $this->_get_next('vrlv3_kisat_tulokset', $jaos);
        }
    }
    
    
     private function _get_next($table, $jaos)
    {
        $result = false;
        $show = false;
        $letter = 'k';
        
        if($table == "vrlv3_kisat_tulokset" || $table == 'vrlv3_kisat_nayttelytulokset'){
            $result = true;
        }
        if($table == "vrlv3_kisat_nayttelytulokset" || $table == 'vrlv3_kisat_nayttelykalenteri'){
            $show = true;
        }
        
        if($result){
              $letter= 't';          

        }
        $data = array();
        $date = new DateTime();
        $date->setTimestamp(time() - 60*5); //nykyhetki miinus 5min, eli ei saa ottaa samaa jonoitemiä uudestaan käsittelyyn 5 minuuttiin

        
        $this->db->where('k.jaos', $jaos);
        $this->db->where('k.vanha', 0);
        $this->db->group_start();
        if($result){
            // tulostaulujen hyvaksytty on NOT NULL -> pelkkä vertailu käyttää indeksiä
            $this->db->where($letter.'.hyvaksytty', '0000-00-00 00:00:00');
        }else{
            $this->db->where($letter.'.hyvaksytty IS NULL OR '.$letter.'.hyvaksytty = \'0000-00-00 00:00:00\'');
        }
        $this->db->group_end();
        $this->db->group_start();
        $this->db->where($letter.'.kasitelty IS NULL OR '.$letter.'.kasitelty < \'' . $date->format('Y-m-d H:i:s') . '\'');
        $this->db->group_end();
        $this->db->order_by($letter.".ilmoitettu", "asc"); 
        $this->db->limit(1); // vain yksi jonoitem käsittelyyn, ei koko jonoa muistiin
        $query = $this->db->get();
        
        if ($query->num_rows() > 0)
        {
            $data = $query->row_array(); 

            $date->setTimestamp(time());
            $user = $this->ion_auth->user()->row();
            $update_data = array('kasitelty' => $date->format('Y-m-d H:i:s'), 'kasittelija'=> $this->ion_auth->user()->row()->tunnus);
            
            $id_key = "kisa_id";
            if($show && $result){
                $id_key = "bis_id";
            }else if($result){
                $id_key = 'tulos_id';
            }
            $this->db->where($id_key, $data[$id_key]);
            $this->db->update($table, $update_data);
                        
        }
        
        return $data;
    }
    
    private function _approve_competition ($jaos, $kisa_id, $approve, $disapprove_msg = false){
        $processing_ok = false;
        $nayttelyt = false;
        $db_table = 'vrlv3_kisat_kisakalenteri';
        
        if($this->kisajarjestelma->nayttelyjaos($jaos)){
            $nayttelyt = true;
            $db_table = 'vrlv3_kisat_nayttelykalenteri';
        }
        
        
        $vrl = $this->ion_auth->user()->row()->tunnus;
        $this->db->trans_start();
        $this->db->select('*');
        
        $this->db->from($db_table);
        
    
        $this->db->where('jaos', $jaos);
        $this->db->where('kisa_id', $kisa_id);
        $this->db->where('kasittelija', $vrl);
        $this->db->where('hyvaksytty', NULL);
        $query = $this->db->get();

        
        if ($query->num_rows() > 0)
        {
            if($approve){
                
                $date = new DateTime();
                $date->setTimestamp(time());
                $insert_data = array('hyvaksytty'=> $date->format('Y-m-d H:i:s'), 'hyvaksyi'=>$vrl);
                $where_data = array('jaos'=> $jaos, 'kisa_id' => $kisa_id, 'kasittelija' => $vrl);
                $this->db->where($where_data);
                $this->db->update($db_table, $insert_data);

                $this->load->model('Tunnukset_model');
                if($nayttelyt){
                    $this->Tunnukset_model->send_message($vrl, $query->result_array()[0]['tunnus'] , "Näyttely #".$query->result_array()[0]['kisa_id']." on hyväksytty kalenteriin!");

                }else {               
                    $this->Tunnukset_model->send_message($vrl, $query->result_array()[0]['tunnus'] , "Kilpailukutsu #".$query->result_array()[0]['kisa_id']." on hyväksytty kalenteriin!");
                }
            }
            
            else {
                    $where_data = array('jaos'=> $jaos, 'kisa_id' => $kisa_id, 'kasittelija' => $vrl);
                    $this->db->delete($db_table, $where_data);
                    $this->load->model('Tunnukset_model');
                    if($nayttelyt){
                        $this->Tunnukset_model->send_message($vrl, $query->result_array()[0]['tunnus'] ,
                                                         "Näyttely #".$query->result_array()[0]['kisa_id']." on hylätty! Syy: " . $disapprove_msg);
                    } else {
                        $this->Tunnukset_model->send_message($vrl, $query->result_array()[0]['tunnus'] ,
                                                         "Kilpailukutsu #".$query->result_array()[0]['kisa_id']." on hylätty! Syy: " . $disapprove_msg);

                    }


            }
            
            $this->db->trans_complete();    
            return true;
        }else {
             $this->db->trans_complete();
             return false;
        }

        
        
    }
    
     private function _approve_result ($jaos, $kisa_id, $approve, $disapprove_msg = false){
        $processing_ok = false;
        $nayttelyt = false;
        $db_comp_table = "";
        $db_res_table = "";
        $db_res_id = "";
        $db_comp_ref_id ="";
        
        $this->db->trans_start();

        $vrl = $this->ion_auth->user()->row()->tunnus;

        if($this->kisajarjestelma->nayttelyjaos($jaos)){
            $nayttelyt = true;
            $db_comp_table = 'vrlv3_kisat_nayttelykalenteri';
            $db_res_table = 'vrlv3_kisat_nayttelytulokset';
            $db_res_id = "bis_id";
            $db_comp_ref_id = "nayttely_id";
            $this->db->select('k.*, t.tunnus as ilmoittaja, t.ilmoitettu as ilmoitettu, t.bis_id');

        }else {
            $db_comp_table = 'vrlv3_kisat_kisakalenteri';
            $db_res_table = 'vrlv3_kisat_tulokset';
            $db_res_id = "kisa_id";
            $db_comp_ref_id = "kisa_id";
            $this->db->select('k.*, t.tunnus as ilmoittaja, t.ilmoitettu as ilmoitettu, t.luokat, t.tulokset, t.hylatyt, t.tulos_id');

        }
        
        $this->db->from($db_comp_table . " as k");
        $this->db->join($db_res_table . " as t", 'k.kisa_id = t.' . $db_comp_ref_id);
        $this->db->where('jaos', $jaos);
        $this->db->where('k.kisa_id', $kisa_id);
        $this->db->where('t.kasittelija', $vrl);
        $this->db->where('t.hyvaksytty', NULL);
        $query = $this->db->get();
        
        
        if ($query->num_rows() > 0)
        {
            if($approve){
                
                $date = new DateTime();
                $date->setTimestamp(time());
                $insert_data = array('hyvaksytty'=> $date->format('Y-m-d H:i:s'), 'hyvaksyi'=>$vrl);
                $where_data = array($db_comp_ref_id => $kisa_id, 'kasittelija' => $vrl);
                $this->db->where($where_data);
                $this->db->update($db_res_table, $insert_data);
                
                                //pikaviesti
                $this->load->model('Tunnukset_model');
                
                if(!$nayttelyt){
                    //statistiikka
                    $this->kisajarjestelma->add_stats($query->result_array()[0], $jaos, $query->result_array()[0]['porrastettu']);
                                    
                    //etuuspisteet
                    if($query->result_array()[0]['porrastettu'] == 0){
                        $ilmo_tunnus = $query->result_array()[0]['ilmoittaja'];
                        $takaaja_tunnus = $query->result_array()[0]['takaaja'];
        
                        //onko takaajan ilmoittama?
                        $takaaja = false;
                        if(isset($takaaja_tunnus) && $takaaja_tunnus != '00000' && $takaaja_tunnus == $ilmo_tunnus){
                            $takaaja = true;
                        }
                        $this->kisajarjestelma->add_etuuspisteet($ilmo_tunnus, $jaos, $query->result_array()[0]['kp'], $query->result_array()[0]['ilmoitettu'], $takaaja);
                    }
                    //ominaisuuspisteet
                    else {
                        $tulos_id = $query->result_array()[0]['tulos_id'];
                        $this->porrastetut->approve_propertyPoints_from_queue($tulos_id);
                    }
                    
                    $this->Tunnukset_model->send_message($vrl, $query->result_array()[0]['ilmoittaja'] , "Kilpailukutsun #".$query->result_array()[0]['kisa_id']." tulokset on hyväksytty!");

                
                } else {
                    $this->Tunnukset_model->send_message($vrl, $query->result_array()[0]['ilmoittaja'] , "Näyttelyn #".$query->result_array()[0]['kisa_id']." tulokset on hyväksytty!");

                }

                    
                

                
            }
            
            else {
                    $where_data = array($db_comp_ref_id => $kisa_id, 'kasittelija' => $vrl);
                    $this->db->delete($db_res_table, $where_data);
                    $insert_data = array('tulokset'=>0);
                    $where_data = array('jaos'=> $jaos, 'kisa_id' => $kisa_id);
                    $this->db->where($where_data);
                    $this->db->update($db_comp_table, $insert_data);
                    
                    $this->load->model('Tunnukset_model');
                    
                    if($nayttelyt){
                        $this->Tunnukset_model->send_message($vrl, $query->result_array()[0]['tunnus'] ,
                                                         "Näyttelyn #".$query->result_array()[0]['kisa_id']." tulokset on hylätty! Syy: " . $disapprove_msg);
  
                    }else  {
                        $this->Tunnukset_model->send_message($vrl, $query->result_array()[0]['tunnus'] ,
                                                         "Kilpailukutsun #".$query->result_array()[0]['kisa_id']." tulokset on hylätty! Syy: " . $disapprove_msg);
                    }


            }
            
            $this->db->trans_complete();    
            return true;
        }else {
             $this->db->trans_complete();
             return false;
        }
        
     }



    
}
    

?>
