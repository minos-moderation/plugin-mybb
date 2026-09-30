<?php

/*
 * Minos — moderacja postów: the plugin's texts, in Polish.
 *
 * `inc/languages/english/minos.lang.php` is a byte-for-byte copy ON PURPOSE: MyBB loads a
 * language file from the forum's language pack and falls back to `english/` when the pack
 * has none, so a forum whose pack is not named `polish` (or an English board run for Polish
 * users) would otherwise get no texts. The plugin serves Polish forums — the gateway
 * assesses Polish — so both copies are Polish. `tests/Plugin/LanguageTest.php` keeps them
 * equal. `{1}`, `{2}` are MyBB's placeholders.
 */

$l['minos_name'] = 'Minos — moderacja postów';
$l['minos_description'] = 'Wysyła nowe posty do bramy Wergiliusza i stosuje werdykt, który brama odsyła podpisanym webhookiem. Adres webhooka do zgłoszenia razem z kluczem: <code>{1}</code>';

$l['minos_group_title'] = 'Minos — moderacja postów';
$l['minos_group_desc'] = 'Moderacja nowych postów przez bramę Wergiliusza. Adres webhooka do zgłoszenia razem z kluczem: {1}';

$l['minos_setting_enabled'] = 'Włączona';
$l['minos_setting_enabled_desc'] = 'Gdy wyłączona, nowe posty trafiają na forum tak, jakby wtyczki nie było. Werdykty dla postów wysłanych wcześniej są nadal stosowane.';
$l['minos_setting_gateway_url'] = 'Adres bramy';
$l['minos_setting_gateway_url_desc'] = 'Adres bramy Wergiliusza. Musi zaczynać się od https:// (http:// tylko dla atrapy bramy uruchomionej na tym samym komputerze).';
$l['minos_setting_api_key'] = 'Klucz API';
$l['minos_setting_api_key_desc'] = 'Klucz B2B (wgb2b_…) wydany dla tego forum. Po zapisaniu pokazujemy tylko jego początek.';
$l['minos_setting_webhook_secret'] = 'Sekret webhooka';
$l['minos_setting_webhook_secret_desc'] = 'Sekret wydrukowany raz, przy wydaniu klucza. Służy do sprawdzania podpisu każdego werdyktu. Po zapisaniu pokazujemy tylko jego początek.';
$l['minos_setting_profile'] = 'Profil oceny';
$l['minos_setting_profile_desc'] = 'Profil, według którego brama ocenia posty. Musi być na liście profili klucza.';
$l['minos_setting_profile_forum_adult'] = 'forum_adult — forum dla dorosłych';
$l['minos_setting_profile_forum_teen'] = 'forum_teen — forum dla młodzieży';
$l['minos_setting_failure_mode'] = 'Tryb awaryjny';
$l['minos_setting_failure_mode_desc'] = 'Co zrobić z postem, gdy brama go nie oceniła (nieocenione), gdy werdykt nie nadszedł w czasie oczekiwania albo gdy brama odrzuciła wysyłkę z powodu błędu konfiguracji.';
$l['minos_setting_failure_mode_fail_closed'] = 'Zostaw w kolejce moderacji (fail-closed)';
$l['minos_setting_failure_mode_fail_open'] = 'Opublikuj bez oceny (fail-open)';
$l['minos_setting_timeout'] = 'Czas oczekiwania na werdykt (minuty)';
$l['minos_setting_timeout_desc'] = 'Po tylu minutach post bez werdyktu dostaje tryb awaryjny. Najmniej 20 (tyle też domyślnie): brama próbuje doręczyć werdykt przez 15 minut, a 5 minut to zapas. Werdykt, który przyjdzie po opublikowaniu postu w trybie fail-open, jest i tak stosowany, dopóki nikt nie ruszył postu.';
$l['minos_setting_censored'] = 'Post ocenzurowany';
$l['minos_setting_censored_desc'] = 'Co zrobić z postem, w którym brama zamaskowała fragmenty (ocenzurowane). Opublikowany post traci formatowanie MyCode; oryginał zostaje w tabeli wtyczki. Post dłuższy niż 3000 znaków albo z zamaskowanym tytułem wątku zawsze zostaje w kolejce.';
$l['minos_setting_censored_publish'] = 'Opublikuj zamaskowany tekst';
$l['minos_setting_censored_queue'] = 'Zostaw w kolejce moderacji';
$l['minos_setting_blocked'] = 'Post zablokowany';
$l['minos_setting_blocked_desc'] = 'Co zrobić z postem, który brama zablokowała (zablokowane).';
$l['minos_setting_blocked_queue'] = 'Zostaw w kolejce moderacji';
$l['minos_setting_blocked_soft_delete'] = 'Usuń (miękko — moderator może przywrócić)';
$l['minos_setting_forums'] = 'Fora';
$l['minos_setting_forums_desc'] = 'Fora, w których wtyczka moderuje nowe posty i wątki.';
$l['minos_setting_skip_moderators'] = 'Pomijaj moderatorów';
$l['minos_setting_skip_moderators_desc'] = 'Posty moderatorów, supermoderatorów i administratorów trafiają na forum bez oceny.';

$l['minos_secret_saved'] = 'Zapisano: <code>{1}</code>. Zostaw pole puste, aby tego nie zmieniać.';
$l['minos_secret_missing'] = 'Nic nie zapisano.';
$l['minos_secret_clear'] = 'Usuń zapisaną wartość';
$l['minos_secret_inactive'] = 'Aktywuj wtyczkę, aby zmienić tę wartość — przy nieaktywnej wtyczce wpisana wartość nie zostałaby zapisana.';
$l['minos_webhook_url_hint'] = 'Adres webhooka do zgłoszenia razem z kluczem: <code>{1}</code>';

$l['minos_task_title'] = 'Minos — moderacja postów';
$l['minos_task_desc'] = 'Ponawia wysyłkę postów do bramy i stosuje tryb awaryjny do postów, dla których werdykt nie nadszedł.';
$l['minos_task_ran'] = 'Minos: tryb awaryjny dla postów bez werdyktu: {1}; ponownie wysłane posty: {2}.';

$l['minos_uninstall_title'] = 'Odinstaluj wtyczkę Minos';
$l['minos_uninstall_question'] = 'Czy usunąć także tabele wtyczki (historię ocen i oryginały ocenzurowanych postów)? „Nie” zostawia je w bazie.';

$l['minos_notice_problems'] = 'Minos nie moderuje nowych postów: {1}. Uzupełnij ustawienia w grupie „Minos — moderacja postów”.';
$l['minos_problem_gateway_url'] = 'adres bramy musi zaczynać się od https://';
$l['minos_problem_api_key'] = 'brak klucza API albo ma on zły format (wgb2b_…)';
$l['minos_problem_webhook_secret'] = 'brak sekretu webhooka';
$l['minos_problem_profile'] = 'nieznany profil oceny';
$l['minos_notice_config_error'] = 'Brama odrzuciła wysyłkę postów (kod: {1}, {2}). Takie posty dostają tryb awaryjny. Sprawdź klucz, profil oceny i adres webhooka zgłoszony dla klucza.';

$l['minos_acp_title'] = 'Minos — moderacja postów';
$l['minos_acp_menu'] = 'Minos — dziennik';
$l['minos_acp_permission'] = 'Czy może przeglądać dziennik Minosa?';
$l['minos_acp_config'] = 'Konfiguracja';
$l['minos_acp_state'] = 'Stan';
$l['minos_acp_state_active'] = 'Działa';
$l['minos_acp_state_disabled'] = 'Wyłączona';
$l['minos_acp_state_problems'] = 'Nie działa: {1}';
$l['minos_acp_webhook'] = 'Adres webhooka';
$l['minos_acp_mode'] = 'Tryb awaryjny';
$l['minos_acp_attention'] = 'Posty, które wymagają uwagi';
$l['minos_acp_attention_desc'] = 'Posty zatrzymane w kolejce moderacji, posty czekające na werdykt, posty opublikowane bez oceny w trybie fail-open i posty, przy których brama zasygnalizowała, że autor może potrzebować wsparcia (wsparcie).';
$l['minos_acp_log'] = 'Dziennik';
$l['minos_acp_none'] = 'Brak wpisów.';
$l['minos_acp_col_post'] = 'Post';
$l['minos_acp_col_status'] = 'Stan';
$l['minos_acp_col_verdict'] = 'Werdykt';
$l['minos_acp_col_categories'] = 'Kategorie';
$l['minos_acp_col_support'] = 'Wsparcie';
$l['minos_acp_col_time'] = 'Czas';
$l['minos_acp_col_event'] = 'Zdarzenie';
$l['minos_acp_col_code'] = 'Kod';
$l['minos_acp_support_yes'] = 'Tak — okaż autorowi wsparcie';

$l['minos_status_pending'] = 'Czeka na werdykt';
$l['minos_status_retry'] = 'Czeka na ponowną wysyłkę';
$l['minos_status_applying'] = 'W trakcie';
$l['minos_status_published'] = 'Opublikowany';
$l['minos_status_auto_published'] = 'Opublikowany bez oceny (tryb awaryjny fail-open)';
$l['minos_status_censored'] = 'Opublikowany ocenzurowany';
$l['minos_status_held'] = 'W kolejce moderacji';
$l['minos_status_deleted'] = 'Usunięty';
$l['minos_status_superseded'] = 'Rozstrzygnięty przez człowieka';
$l['minos_status_gone'] = 'Post nie istnieje';

$l['minos_reason_timeout'] = 'brak werdyktu w czasie';
$l['minos_reason_config_error'] = 'błąd konfiguracji';
$l['minos_reason_no_text'] = 'post bez tekstu';
$l['minos_reason_edited'] = 'edytowany przed werdyktem';
$l['minos_reason_truncated'] = 'wpis dłuższy niż 3000 znaków — oceniono początek';

$l['minos_event_config_error'] = 'Błąd konfiguracji';
$l['minos_event_retry'] = 'Ponowienie wysyłki';
$l['minos_event_recovered'] = 'Wysyłka znów działa';
$l['minos_event_timeout'] = 'Brak werdyktu w czasie';
