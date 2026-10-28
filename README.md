# Minos — moderacja postów dla MyBB

Wtyczka do forum MyBB 1.8, która każdy nowy post i wątek zatrzymuje w kolejce moderacji,
wysyła jego tekst do bramy Wergiliusza, a werdykt odesłany przez bramę stosuje sama:
bezpieczny post publikuje, ocenzurowany publikuje z zamaskowanymi fragmentami,
zablokowany zostawia w kolejce albo usuwa — tak, jak ustawi administrator. Część projektu
Minos.

> Trasa B2B bramy nie jest jeszcze otwarta produkcyjnie. Do czasu otwarcia wtyczkę można
> sprawdzić z atrapą bramy (opis dla programistów: `docs/development.md`).

## Jak to działa

1. Użytkownik wysyła nowy post albo wątek. Wtyczka prosi MyBB, żeby potraktował go jak
   post użytkownika pod moderacją: post trafia do zwykłej kolejki moderacji forum
   (niewidoczny, liczniki forum nie uwzględniają go aż do publikacji — dokładnie jak przy
   ręcznej moderacji).
2. Wtyczka wysyła tekst postu do bramy (`POST /api/v1/b2b/oceny`). Brama odpowiada od
   razu, że przyjęła post do oceny; autor czeka na tę odpowiedź najwyżej 10 sekund. Gdy
   brama przed chwilą poprosiła o ponowienie (limit, przeciążenie, `503`) albo nie
   odpowiedziała, wtyczka do chwili, którą wskazała brama (bez wskazania — przez minutę),
   nie wysyła postów w żądaniu autora: autor nie czeka, a post wyśle zadanie.
3. Po ocenie brama odsyła werdykt na adres webhooka forum,
   `https://<adres-forum>/minos-webhook.php`, podpisany sekretem webhooka. Wtyczka sprawdza
   podpis i stosuje werdykt.
4. Co 5 minut zadanie MyBB „Minos — moderacja postów” ponawia wysyłkę postów, których
   brama chwilowo nie przyjęła, wysyła poprawione przez autorów posty czekające na werdykt
   i stosuje tryb awaryjny do postów, dla których werdykt nie nadszedł w czasie
   oczekiwania.

## Wymagania

- MyBB 1.8 (sprawdzone na 1.8.41: test automatyczny instaluje prawdziwe forum MyBB 1.8.41
  na MySQL i PostgreSQL i przechodzi na nim całą drogę postu — wstrzymanie, wysyłkę,
  podpisany werdykt, maskę, wyłączenie i odinstalowanie) i PHP 7.4 lub nowszy
  z rozszerzeniami `curl` i `json`.
- Forum pod adresem **HTTPS z nazwą domeny**, osiągalnym z internetu. Brama doręcza
  werdykty tylko na `https://`, tylko na nazwy hostów z listy klucza i nie podąża za
  przekierowaniami — adres webhooka musi odpowiadać bezpośrednio (bez przekierowania
  z `http` na `https` ani z `www` na domenę bez `www`).
- Klucz B2B (`wgb2b_…`) i sekret webhooka, wydane przez operatora bramy. Sekret jest
  pokazywany tylko raz, przy wydaniu klucza.
- **Osobny klucz dla kopii testowej forum.** Nie wpisuj klucza i sekretu forum produkcyjnego
  w kopii testowej: jej posty zużywałyby limity klucza produkcji, a brama doręcza werdykty
  na adres webhooka zapisany przy kluczu, czyli na forum produkcyjne. Tam werdykt dla posta
  kopii trafiłby na post o tym samym numerze — jeśli taki akurat czeka na werdykt, zostałby
  w kolejce dla człowieka (tekst inny niż wysłany do oceny). Poproś operatora bramy
  o osobny klucz z adresem webhooka kopii.
- Działające zadania MyBB. Bez dodatkowej konfiguracji MyBB uruchamia je przy okazji ruchu
  na forum, więc na cichym forum ponowienia i tryb awaryjny czekają na odwiedzających.
  Zalecamy prawdziwy cron, np. `* * * * * php /ścieżka/do/forum/task.php` — przy każdym
  wywołaniu z wiersza poleceń MyBB uruchamia najbliższe zaległe zadanie.

## Instalacja

1. Pobierz paczkę `minos-mybb-<wersja>.zip` (albo zbuduj ją poleceniem
   `bin/build-zip.sh`).
2. Skopiuj **zawartość katalogu `Upload/`** do katalogu głównego forum. Trafią tam:

   | Plik lub katalog | Po co |
   |---|---|
   | `minos-webhook.php` | adres, na który brama odsyła werdykty |
   | `inc/plugins/minos.php` | wtyczka |
   | `inc/plugins/minos/` | klasy wtyczki i dołączona biblioteka `client-php` (`vendor/`) |
   | `inc/tasks/minos.php` | zadanie wykonywane co 5 minut |
   | `inc/languages/polish/minos.lang.php` | teksty wtyczki |
   | `inc/languages/english/minos.lang.php` | te same teksty po polsku — MyBB sięga po ten plik, gdy pakiet językowy forum nie ma własnego |

3. W panelu administratora: **Konfiguracja → Wtyczki → „Minos — moderacja postów” →
   Zainstaluj i aktywuj**. Instalacja tworzy grupę ustawień, dwie tabele
   (`<prefiks>minos_pending`, `<prefiks>minos_log`) i zadanie.
4. **Konfiguracja → Ustawienia → Minos — moderacja postów**: wpisz klucz API i sekret
   webhooka, wybierz profil oceny i tryb awaryjny, a na końcu ustaw **Włączona = tak**.
   Świeża instalacja startuje wyłączona.
5. Przekaż operatorowi bramy adres webhooka do zapisania przy kluczu:
   `https://<adres-forum>/minos-webhook.php`. Wtyczka pokazuje go w opisie wtyczki,
   w opisie grupy ustawień i przy polu sekretu.
6. Napisz próbny post jako zwykły użytkownik. W **Narzędzia i konserwacja → Dzienniki →
   Minos — dziennik** widać posty, które wymagają uwagi (czekające na werdykt, zatrzymane
   w kolejce, opublikowane bez oceny — pełna lista niżej, w „Panel administratora”), oraz
   dziennik zdarzeń; post opublikowany po werdykcie `bezpieczne` znika z tej listy, chyba
   że brama oznaczyła go sygnałem „wsparcie”.

**Aktualizacja do nowej wersji**: dezaktywuj wtyczkę, podmień pliki i aktywuj ją ponownie.
Aktywacja dodaje do tabel wtyczki kolumny, których wymaga nowa wersja (dane zostają); bez
niej nowa wersja nie zapisze postów do tabeli. Posty, które czekały na werdykt w czasie
dezaktywacji, wtyczka wysyła ponownie przy pierwszym przebiegu zadania.

## Ustawienia

| Ustawienie | Znaczenie | Domyślnie |
|---|---|---|
| Włączona | Wyłączona wtyczka nic nie robi: nie zatrzymuje nowych postów, nie stosuje werdyktów ani trybu awaryjnego. Posty już zatrzymane zostają w kolejce moderacji dla ludzi, a werdykty doręczone w tym czasie przepadają. | nie |
| Adres bramy | Adres bramy Wergiliusza. Musi zaczynać się od `https://` (`http://` tylko dla atrapy bramy na tym samym komputerze). | `https://gateway.wergiliusz.app` |
| Klucz API | Klucz B2B `wgb2b_…`. Po zapisaniu panel pokazuje tylko jego początek. Puste pole przy zapisie zostawia zapisany klucz; pole „Usuń zapisaną wartość” go kasuje. | — |
| Sekret webhooka | Sekret do sprawdzania podpisu werdyktów. Zapisywany i pokazywany jak klucz. | — |
| Profil oceny | `forum_adult` (forum dla dorosłych) albo `forum_teen` (forum dla dzieci i młodzieży). Musi być na liście profili klucza. | `forum_adult` |
| Tryb awaryjny | Co zrobić z postem bez werdyktu: **zostawić w kolejce moderacji** (fail-closed) albo **opublikować bez oceny** (fail-open). | fail-closed |
| Czas oczekiwania na werdykt | Minuty, po których post bez werdyktu dostaje tryb awaryjny. **Najmniej 20** = 15 minut, przez które brama próbuje doręczyć werdykt, i 5 minut zapasu; mniejsza wartość jest traktowana jak 20. **Najwięcej 1440** (doba); większa jest traktowana jak 1440. | 20 |
| Post ocenzurowany | **Opublikuj zamaskowany tekst** albo **zostaw w kolejce moderacji**. | opublikuj |
| Post zablokowany | **Zostaw w kolejce moderacji** albo **usuń (miękko)** — moderator może go przywrócić. | zostaw w kolejce |
| Fora | Fora, w których wtyczka moderuje nowe posty. | wszystkie |
| Pomijaj moderatorów | Posty moderatorów, supermoderatorów i administratorów trafiają na forum bez oceny. | tak |

Gdy wtyczka jest włączona, ale czegoś brakuje (klucza, sekretu, poprawnego adresu bramy,
znanego profilu), nie wysyła postów do oceny: każdy nowy post od razu dostaje tryb
awaryjny, a każda strona panelu administratora mówi, czego brakuje.

**Klucz i sekret zapisuj przy aktywnej wtyczce.** Zapisuje je kod wtyczki, więc przy
dezaktywowanej wtyczce pola klucza i sekretu są wyłączone (z informacją, dlaczego) —
wpisana wartość i tak nie zostałaby zapisana.

## Które posty trafiają do oceny

Nowe posty i wątki pisane przez użytkowników i gości na forum, a także poprawki postów, które
czekają na werdykt (niżej). Wtyczka **nie** dotyka: szkiców, edycji opublikowanych postów,
postów moderatorów (gdy tak ustawiono), postów w forach spoza wyboru ani
postów, które MyBB i tak kieruje do ręcznej moderacji (forum moderuje nowe posty albo
użytkownik jest pod moderacją) — o tych decydują ludzie, tak jak ustawiono forum.

## Co jest wysyłane, a czego wtyczka nigdy nie wysyła

Wysyłane jest tylko:
- identyfikator `mybb:<numer postu>` (po poprawce postu czekającego na werdykt:
  `mybb:<numer postu>.<numer wersji>`) — bez żadnej treści;
- tekst postu **taki, jaki widzą czytelnicy**, tylko pierwsze 3000 znaków (limit bramy).
  Na początku to, co czytelnik widzi nad postem: przy nowym wątku jego tytuł, a gdy wątek
  ma ankietę — także jej pytanie i opcje; przy odpowiedzi jej własny temat, jeśli autor
  zmienił domyślne „RE: <tytuł wątku>” (MyBB pokazuje temat odpowiedzi w widoku drzewa,
  w wynikach wyszukiwania, w wersji do druku i w podpowiedzi numeru postu). Sam tytuł
  albo sam temat nigdy nie jest wysyłany: post, którego treść nie ma tekstu, a wątek nie ma
  ankiety, od razu dostaje tryb awaryjny (niżej). Wtyczka odtwarza to, co MyBB pokazuje na
  stronie:
  - na forum bez HTML (domyślnie) znaki `<…>` są zwykłym tekstem i zostają w całości;
    na forum z HTML znaczniki znikają, ale tekst z atrybutów `alt` i `title` zostaje;
  - cytat `[quote=NAZWA]` staje się „NAZWA napisał(a):” i cytowanym tekstem;
    `[url=ADRES]opis[/url]` — „opis (ADRES)”; `[img]ADRES[/img]` i filmy — samym adresem;
  - MyCode, którego MyBB nie rozpozna (nieznany znacznik, znacznik bez pary, forum
    z wyłączonym MyCode), zostaje dosłownie — tak jak widzi go czytelnik;
- profil oceny;
- sygnały antyspamowe: liczba linków w poście, domeny tych linków (najwyżej 10, np.
  `example.com`) i — dla zarejestrowanego autora — czy to jego pierwszy post: czy licznik
  postów jego konta w MyBB wynosił 0, gdy post był wysyłany pierwszy raz (ponowienie
  wysyła tę samą wartość, a dla gościa ten sygnał nie jest wysyłany).

**Nigdy** nie są wysyłane: adres e-mail, adres IP, nazwa ani numer użytkownika, załączniki
ani dalsza część postu ponad 3000 znaków.

## Werdykty

| Werdykt bramy | Co robi wtyczka |
|---|---|
| `bezpieczne` | publikuje post (zatwierdza go jak moderator; liczniki forum, wątku i użytkownika są przeliczane) |
| `ocenzurowane` | publikuje tekst z zamaskowanymi fragmentami (`█`) albo zostawia post w kolejce — według ustawienia |
| `zablokowane` | zostawia post w kolejce moderacji albo usuwa go miękko — według ustawienia |
| `nieocenione` | tryb awaryjny |
| `wsparcie` | niezależnie od werdyktu oznacza post w dzienniku wtyczki: autor może potrzebować wsparcia, nie kary |

**Post dłuższy niż 3000 znaków**: brama oceniła tylko jego początek, więc werdykt
`bezpieczne` nie wystarcza do publikacji — post dostaje tryb awaryjny (fail-closed: zostaje
w kolejce; fail-open: zostaje opublikowany). Uwagę „wpis dłuższy niż 3000 znaków — oceniono
początek” widać w dzienniku wtyczki (Minos — dziennik, lista postów wymagających uwagi,
kolumna „Werdykt”), nie w kolejce moderacji MyBB. Werdykt `zablokowane` działa normalnie, a `ocenzurowane` zawsze zostawia
taki post w kolejce.

Brama maskuje tekst, który zobaczyli czytelnicy, więc wtyczka publikuje zamaskowany tekst
tylko wtedy, gdy jest on **dokładnie** tym, co wysłała, z niektórymi znakami zastąpionymi
przez `█`, a post jest zwykłym tekstem. **Post z formatowaniem (MyCode, cytat, link)
zostaje w kolejce, a zamaskowany tekst widać w dzienniku** (Minos — dziennik, kolumna
„Tekst zamaskowany przez bramę”). Tak samo zostaje w kolejce zwykły tekst z nadmiarowymi
białymi znakami — podwójnymi spacjami, tabulatorami, twardymi spacjami, spacjami na
początku lub końcu wiersza albo więcej niż jednym pustym wierszem z rzędu: wtyczka wysyła
go z takimi odstępami ściśniętymi, więc maska nie odpowiada zapisanej treści znak w znak
(różnica samych końców wiersza CR/LF nie przeszkadza). Oryginał opublikowanego postu zostaje w tabeli wtyczki
(przez 30 dni). Post ocenzurowany zawsze zostaje w kolejce, gdy: był dłuższy niż 3000
znaków, brama zamaskowała coś w tytule wątku, w temacie odpowiedzi albo w ankiecie (wtyczka
nigdy ich nie zmienia), zamaskowany tekst różni się od wysłanego
czymś innym niż znaki `█` (inna długość, inne litery), brama nie przysłała zamaskowanego
tekstu albo nie udało się zapisać zamaskowanej treści.

Werdykt dotyczy tylko tekstu, który wtyczka wysłała: przed zastosowaniem każdego werdyktu
wtyczka porównuje skrót (SHA-256) wysłanego tekstu z tekstem postu w tej chwili — sam
tekst nie jest przechowywany. Jeśli treść zmieniła się z pominięciem edycji (np. przez inną
wtyczkę), werdykt nie jest stosowany: post zostaje w kolejce dla człowieka („tekst postu
inny niż wysłany do oceny”), a post opublikowany wcześniej w trybie fail-open zostaje taki,
jaki jest.

Jeśli moderator zdąży zatwierdzić albo usunąć post, zanim nadejdzie werdykt, wtyczka go nie
rusza — decyzja człowieka wygrywa.

**Poprawka postu czekającego na werdykt.** Werdykt o poprzednim tekście nie ma już
zastosowania, więc:
- gdy post poprawia jego autor (ktoś, kto nie jest moderatorem tego forum), wtyczka wysyła
  poprawiony tekst do oceny jeszcze raz, jako kolejną wersję (`mybb:<numer postu>.1`,
  `.2`…), z nowym czasem oczekiwania. Wysyła ją zadanie przy najbliższym przebiegu, nie
  żądanie autora. Werdykt o wcześniejszej wersji, jeśli jeszcze nadejdzie, jest pomijany.
  Tak samo po poprawce postu, który tryb awaryjny zostawił w kolejce, bo werdykt nie
  nadszedł w czasie, brama odrzuciła wysyłkę z powodu błędu konfiguracji, nie udało się
  odczytać tekstu albo post nie miał tekstu do oceny;
- gdy post poprawia moderator, decyduje człowiek: wtyczka niczego nie wysyła, a post
  zostaje w kolejce moderacji i na liście „Posty, które wymagają uwagi” (stan
  „Rozstrzygnięty przez człowieka”, powód „edytowany przez moderatora przed werdyktem”).

Własny post w kolejce moderacji autor może poprawić tylko wtedy, gdy forum na to pozwala
(ustawienie MyBB `showownunapproved`, domyślnie wyłączone); poza tym poprawiają go
moderatorzy.

## Tryb awaryjny: fail-open czy fail-closed

Wtyczka nigdy nie zgaduje werdyktu. Tryb awaryjny rozstrzyga, co zrobić z postem, gdy
werdyktu nie ma:
- brama odpowiedziała `nieocenione` (nie zdążyła ocenić albo nie rozpoznała odpowiedzi);
- werdykt nie nadszedł w czasie oczekiwania;
- brama odmówiła przyjęcia postu do chwili, która wypada po końcu jego czasu oczekiwania
  (np. wyczerpany dobowy limit klucza albo przeciążenie) — tryb awaryjny od razu;
- brama odrzuciła wysyłkę z powodu błędu konfiguracji (np. zły klucz, profil spoza listy
  klucza, brak zapisanego webhooka) albo odpowiedziała czymś innym niż przyjęciem `202`
  (np. stroną dostawcy hostingu z kodem `200`) — kod błędu pojawia się w panelu
  administratora;
- wtyczka jest włączona, ale jej ustawienia są niekompletne (brak klucza albo sekretu,
  adres bramy bez `https://`, nieznany profil) — nic nie jest wysyłane;
- post nie ma tekstu do oceny (np. sam załącznik) — także nowy wątek albo odpowiedź
  z własnym tematem, jeśli ich treść nie ma tekstu: sam tytuł nie jest tekstem do oceny
  i nie jest wysyłany. Pytanie i opcje ankiety są tekstem, więc wątek z ankietą i pustą
  treścią jest wysyłany z tytułem i ankietą;
- nie udało się odczytać tekstu postu (np. treść tak zbudowana, że przetwarzanie wyrażeń
  regularnych przerywa się z błędem) — wtyczka nie wysyła wtedy niczego, nawet samego
  tytułu wątku, a w dzienniku zapisuje zdarzenie z numerem postu;
- post był dłuższy niż 3000 znaków, a brama uznała jego początek za bezpieczny.

**fail-closed** (domyślnie) zostawia taki post w kolejce moderacji dla człowieka.
**fail-open** publikuje go bez oceny — a jeśli werdykt przyjdzie później, wtyczka nadal go
zastosuje, dopóki nikt nie ruszył postu: `zablokowane` odsyła post z powrotem do kolejki
moderacji, `ocenzurowane` postępuje według ustawienia „Post ocenzurowany”. Jeśli w tym czasie
moderator zdecydował o poście albo post został edytowany, decyzja człowieka jest ostateczna.

Które odpowiedzi bramy są ponawiane: `429` (limity klucza, wspólny limit bramy, pełna
kolejka), `503` i każde inne `5xx`, `408` oraz brak odpowiedzi (błąd połączenia, przekroczony
czas). Przyjęciem jest wyłącznie `202`. Każda inna odpowiedź — pozostałe `4xx`,
przekierowanie, `2xx` inne niż `202` — to błąd konfiguracji: tryb awaryjny od razu i
komunikat z kodem w panelu administratora.

Gdy brama jest chwilowo przeciążona albo niedostępna (lub nie ma połączenia albo
połączenie przekroczyło czas — `408`), post czeka: zadanie ponawia wysyłkę po czasie
wskazanym przez bramę albo po coraz dłuższej przerwie (od 1 minuty do 1 godziny). Jeśli do końca czasu oczekiwania się nie uda — tryb awaryjny.
Taka odmowa wstrzymuje wszystkie wysyłki forum do chwili wskazanej przez bramę (bez
wskazania — na minutę): w tym czasie wtyczka nie wysyła nowych postów (autor nie czeka na
bramę, post czeka na zadanie), a zadanie nie wysyła ponowień. Zadanie przerywa przebieg
na pierwszej paczce, której brama nie przyjęła.
Gdy czas wskazany przez bramę kończy się dopiero po czasie oczekiwania postu (np. po
wyczerpaniu dobowego limitu klucza brama odmawia do końca doby), post od razu dostaje tryb
awaryjny, bez czekania. Przy wyczerpanym dobowym limicie i przy przeciążeniu bramy każda
strona panelu administratora pokazuje, do której godziny brama nie przyjmuje postów;
komunikat znika po pierwszej przyjętej wysyłce albo po tej godzinie. Do tej godziny
wtyczka nie wysyła postów: nowy post, którego czas oczekiwania skończy się wcześniej, od
razu dostaje tryb awaryjny.

Gdy obsługa jednego postu kończy się błędem (np. wyjątkiem innej wtyczki podpiętej pod
moderację MyBB), pozostałe posty są obsługiwane normalnie. Webhook odpowiada wtedy błędem
`500`, więc brama doręcza werdykt ponownie, a zadanie próbuje znowu przy następnym
przebiegu. Po trzech nieudanych próbach wtyczka nie robi już nic z postem: zostaje on dla
człowieka z uwagą „błąd przy stosowaniu werdyktu (3 próby)”. Dziennik zapisuje krok i
numer postu — nigdy treść błędu.

## Panel administratora

- **Komunikat na każdej stronie panelu**, gdy wtyczka jest włączona, ale nie może działać
  (czego brakuje), gdy brama odrzuciła ostatnią wysyłkę z powodu błędu konfiguracji
  (kod błędu i czas) albo gdy brama nie przyjmuje postów do określonej godziny (wyczerpany
  dobowy limit klucza, przeciążenie — kod i godzina). Znika po pierwszej udanej wysyłce.
  Także gdy webhook odrzuca doręczenia z nieprawidłowym podpisem: ile ich było od
  ostatniego przyjętego doręczenia, kiedy przyszło ostatnie i na co wskazuje powód — zły
  sekret webhooka, zegar serwera przesunięty o ponad 5 minut albo nagłówek podpisu, który
  nie pochodzi od bramy. Dzięki temu literówkę w sekrecie widać bez zaglądania do logów
  serwera (brama dostaje tylko odpowiedź `401`, bez powodu). Ten komunikat znika po
  pierwszym doręczeniu z poprawnym podpisem albo po zapisaniu nowego sekretu.
- **Narzędzia i konserwacja → Dzienniki → Minos — dziennik**: stan konfiguracji, adres
  webhooka, posty wymagające uwagi (zatrzymane w kolejce, czekające na werdykt, opublikowane
  bez oceny w trybie fail-open, rozstrzygnięte przez człowieka przed werdyktem i oznaczone
  jako potrzebujące wsparcia; przy poście
  ocenzurowanym zatrzymanym w kolejce — tekst zamaskowany przez bramę) oraz dziennik
  zdarzeń. Obie tabele pokazują po 50 najnowszych wierszy. Dziennik zawiera wyłącznie kody
  i numery postów — nigdy treść, klucz ani sekret. Wpisy starsze niż 30 dni są usuwane.
- **Werdykty z ostatnich 7 dni** (na tej samej stronie): mediana (P50) i 95. percentyl (P95)
  czasu od przyjęcia postu przez bramę (odpowiedź `202`) do doręczenia werdyktu oraz liczba
  werdyktów `bezpieczne`, `ocenzurowane`, `zablokowane` i `nieocenione` — takich, jakie
  wydała brama, niezależnie od tego, co wtyczka potem zrobiła z postem (np. tryb awaryjny
  dla postu dłuższego niż 3000 znaków). Czas jest liczony tylko z werdyktów, przy których
  wtyczka zapisała chwilę przyjęcia (werdykt potrafi wyprzedzić odpowiedź `202`). Liczby
  pochodzą z tabeli wtyczki: bez treści postów i bez zapytań do bramy.
- **Jak długo wtyczka przechowuje dane postu**: wiersz rozstrzygniętego postu — z werdyktem,
  kategoriami, oryginałem opublikowanego postu ocenzurowanego i zamaskowanym tekstem
  postu zatrzymanego w kolejce — jest usuwany 30 dni po rozstrzygnięciu. Sam wysłany
  tekst nie jest przechowywany (tylko jego skrót SHA-256). Po tym czasie w rejestrach MyBB
  nie zostaje nic z pracy wtyczki poza samym postem: zatwierdzenia, przywrócenia do kolejki
  i miękkie usunięcia, które wykonuje wtyczka, nie trafiają do dziennika moderatorów (MyBB
  zapisuje tam działania wykonane na stronach moderatorów, nie wywołania, których używa
  wtyczka), MyBB 1.8 nie prowadzi historii edycji (zamaskowany tekst zastępuje treść postu
  bez śladu), a dziennik zadań MyBB dostaje z każdego przebiegu tylko dwie liczby.

## Ograniczenia

- Ocenianych jest tylko pierwsze 3000 znaków postu.
- Temat odpowiedzi jest oceniany tylko wtedy, gdy autor go zmienił — domyślne „RE: <tytuł
  wątku>” (a w odpowiedzi na konkretny post: „RE: <temat tego postu>”) nie mówi nic nowego;
  tytuł nowego wątku — zawsze. Czy temat odpowiedzi idzie do oceny, wtyczka ustala przy
  pierwszej wysyłce; późniejsza zmiana tytułu wątku tego nie zmienia, a poprawka tematu
  przez autora wysyła odpowiedź jeszcze raz.
- Ankieta jest oceniana razem z pierwszym postem wątku tylko wtedy, gdy powstała, kiedy wątek
  czekał na werdykt albo stał w kolejce bez oceny (np. z pustą treścią). MyBB pozwala dodać ankietę do wątku z kolejki moderacji tylko
  moderatorom (formularz ankiety odmawia autorowi), więc autor dodaje ją zwykle po publikacji
  wątku — takiej ankiety wtyczka nie ocenia, tak jak poprawek opublikowanych postów. Ankietę
  zmieniają w MyBB tylko moderatorzy: zmiana ankiety wątku czekającego na werdykt, jak
  poprawka moderatora, zostawia wątek ludziom.
- Edycje opublikowanych postów nie są ponownie oceniane (poza zakresem tej wersji).
- **Subskrybenci wątku i forum NIE dostają powiadomień** (e-mail ani prywatnej wiadomości)
  o postach, które przeszły przez wtyczkę — także po ich publikacji. MyBB wysyła je tylko
  w chwili dodania posta widocznego od razu; przy zatwierdzeniu z kolejki moderacji nie
  wysyła ich wcale (tak samo jest przy ręcznej moderacji), a nie udostępnia funkcji, którą
  wtyczka mogłaby je wysłać.
- Informacja „wsparcie” jest widoczna tylko w dzienniku wtyczki, nie w kolejce moderacji
  MyBB.
- Klucz i sekret są przechowywane jak inne ustawienia MyBB: w bazie danych i w pliku
  `inc/settings.php`, jawnym tekstem. Panel administratora pokazuje tylko ich początek
  (a strony „Edytuj ustawienie” MyBB dla nich nie otwiera), ale zobaczy je każdy, kto ma
  dostęp do bazy, jej kopii zapasowych albo do plików forum.
- Gdy wtyczka jest dezaktywowana albo wyłączona („Włączona = nie”), webhook odpowiada
  `404` i nie stosuje werdyktów, a zadanie nie stosuje trybu awaryjnego ani nie ponawia
  wysyłki; posty już zatrzymane zostają w kolejce moderacji dla ludzi. Brama ponawia
  doręczenie werdyktu najwyżej przez kilkanaście minut, potem go porzuca. Po ponownym
  włączeniu (albo aktywacji) pierwszy przebieg zadania wysyła jeszcze raz posty, które
  wciąż czekały na werdykt, z nowym czasem oczekiwania — zamiast hurtem stosować do nich
  tryb awaryjny.

## Odinstalowanie

**Konfiguracja → Wtyczki → Odinstaluj** usuwa ustawienia i zadanie. Panel zapyta, czy
usunąć także tabele wtyczki (historię ocen i oryginały ocenzurowanych postów); „Nie”
zostawia je w bazie — ponowna instalacja z nich skorzysta. Potem usuń pliki wymienione
w instrukcji instalacji.

## Licencja

GPL-2.0 (plik `LICENSE`). Dołączona biblioteka `minos-moderation/client-php` jest na
licencji MIT.
