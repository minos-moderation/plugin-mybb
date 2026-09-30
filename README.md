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
2. Wtyczka wysyła tekst postu do bramy (`POST /api/v1/b2b/oceny`, do 10 sekund). Brama
   odpowiada od razu, że przyjęła post do oceny.
3. Po ocenie brama odsyła werdykt na adres webhooka forum,
   `https://<adres-forum>/minos-webhook.php`, podpisany sekretem webhooka. Wtyczka sprawdza
   podpis i stosuje werdykt.
4. Co 5 minut zadanie MyBB „Minos — moderacja postów” ponawia wysyłkę postów, których
   brama chwilowo nie przyjęła, i stosuje tryb awaryjny do postów, dla których werdykt
   nie nadszedł w czasie oczekiwania.

## Wymagania

- MyBB 1.8 (sprawdzone na 1.8.41) i PHP 7.4 lub nowszy z rozszerzeniami `curl` i `json`.
- Forum pod adresem **HTTPS z nazwą domeny**, osiągalnym z internetu. Brama doręcza
  werdykty tylko na `https://`, tylko na nazwy hostów z listy klucza i nie podąża za
  przekierowaniami — adres webhooka musi odpowiadać bezpośrednio (bez przekierowania
  z `http` na `https` ani z `www` na domenę bez `www`).
- Klucz B2B (`wgb2b_…`) i sekret webhooka, wydane przez operatora bramy. Sekret jest
  pokazywany tylko raz, przy wydaniu klucza.
- Działające zadania MyBB (uruchamiane przez ruch na forum albo przez crona).

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
   webhooka, wybierz profil oceny i tryb awaryjny.
5. Przekaż operatorowi bramy adres webhooka do zapisania przy kluczu:
   `https://<adres-forum>/minos-webhook.php`. Wtyczka pokazuje go w opisie wtyczki,
   w opisie grupy ustawień i przy polu sekretu.
6. Napisz próbny post jako zwykły użytkownik. Stan każdego wysłanego postu widać
   w **Narzędzia i konserwacja → Dzienniki → Minos — dziennik**.

## Ustawienia

| Ustawienie | Znaczenie | Domyślnie |
|---|---|---|
| Włączona | Wyłączona wtyczka nie zatrzymuje nowych postów; werdykty dla postów wysłanych wcześniej są nadal stosowane. | tak |
| Adres bramy | Adres bramy Wergiliusza. Musi zaczynać się od `https://` (`http://` tylko dla atrapy bramy na tym samym komputerze). | `https://gateway.wergiliusz.app` |
| Klucz API | Klucz B2B `wgb2b_…`. Po zapisaniu panel pokazuje tylko jego początek. Puste pole przy zapisie zostawia zapisany klucz; pole „Usuń zapisaną wartość” go kasuje. | — |
| Sekret webhooka | Sekret do sprawdzania podpisu werdyktów. Zapisywany i pokazywany jak klucz. | — |
| Profil oceny | `forum_adult` (forum dla dorosłych) albo `forum_teen` (forum dla młodzieży). Musi być na liście profili klucza. | `forum_adult` |
| Tryb awaryjny | Co zrobić z postem bez werdyktu: **zostawić w kolejce moderacji** (fail-closed) albo **opublikować bez oceny** (fail-open). | fail-closed |
| Czas oczekiwania na werdykt | Minuty, po których post bez werdyktu dostaje tryb awaryjny. **Najmniej 20** = 15 minut, przez które brama próbuje doręczyć werdykt, i 5 minut zapasu; mniejsza wartość jest traktowana jak 20. | 20 |
| Post ocenzurowany | **Opublikuj zamaskowany tekst** albo **zostaw w kolejce moderacji**. | opublikuj |
| Post zablokowany | **Zostaw w kolejce moderacji** albo **usuń (miękko)** — moderator może go przywrócić. | zostaw w kolejce |
| Fora | Fora, w których wtyczka moderuje nowe posty. | wszystkie |
| Pomijaj moderatorów | Posty moderatorów, supermoderatorów i administratorów trafiają na forum bez oceny. | tak |

Gdy wtyczka jest włączona, ale czegoś brakuje (klucza, sekretu, poprawnego adresu bramy),
nie zatrzymuje nowych postów, a każda strona panelu administratora mówi, czego brakuje.

**Klucz i sekret zapisuj przy aktywnej wtyczce.** Zapisuje je kod wtyczki, więc przy
dezaktywowanej wtyczce pola klucza i sekretu są wyłączone (z informacją, dlaczego) —
wpisana wartość i tak nie zostałaby zapisana.

## Które posty trafiają do oceny

Nowe posty i wątki pisane przez użytkowników i gości na forum. Wtyczka **nie** dotyka:
szkiców, edycji, postów moderatorów (gdy tak ustawiono), postów w forach spoza wyboru ani
postów, które MyBB i tak kieruje do ręcznej moderacji (forum moderuje nowe posty albo
użytkownik jest pod moderacją) — o tych decydują ludzie, tak jak ustawiono forum.

## Co jest wysyłane, a czego wtyczka nigdy nie wysyła

Wysyłane jest tylko:
- identyfikator `mybb:<numer postu>` — bez żadnej treści;
- tekst postu **taki, jaki widzą czytelnicy**, tylko pierwsze 3000 znaków (limit bramy);
  przy nowym wątku z tytułem na początku. Wtyczka odtwarza to, co MyBB pokazuje na stronie:
  - na forum bez HTML (domyślnie) znaki `<…>` są zwykłym tekstem i zostają w całości;
    na forum z HTML znaczniki znikają, ale tekst z atrybutów `alt` i `title` zostaje;
  - cytat `[quote=NAZWA]` staje się „NAZWA napisał(a):” i cytowanym tekstem;
    `[url=ADRES]opis[/url]` — „opis (ADRES)”; `[img]ADRES[/img]` i filmy — samym adresem;
  - MyCode, którego MyBB nie rozpozna (nieznany znacznik, znacznik bez pary, forum
    z wyłączonym MyCode), zostaje dosłownie — tak jak widzi go czytelnik;
- profil oceny;
- sygnały antyspamowe: liczba linków w poście, domeny tych linków (najwyżej 10, np.
  `example.com`) i — dla zarejestrowanego autora — czy to jego pierwszy post.

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
w kolejce z uwagą „wpis dłuższy niż 3000 znaków — oceniono początek”; fail-open: zostaje
opublikowany). Werdykt `zablokowane` działa normalnie, a `ocenzurowane` zawsze zostawia
taki post w kolejce.

Opublikowany post ocenzurowany jest **zwykłym tekstem**: traci formatowanie MyCode, bo brama
maskuje tekst, który zobaczyli czytelnicy. Oryginał zostaje w tabeli wtyczki (przez 90 dni).
Post ocenzurowany zawsze zostaje w kolejce, gdy: był dłuższy niż 3000 znaków, brama
zamaskowała coś w tytule wątku, zamaskowany tekst ma inną długość niż wysłany, brama nie
przysłała zamaskowanego tekstu albo nie udało się zapisać zamaskowanej treści.

Jeśli moderator zdąży zatwierdzić albo usunąć post, zanim nadejdzie werdykt, wtyczka go nie
rusza — decyzja człowieka wygrywa. Edycja postu czekającego na werdykt też zostawia go
ludziom: werdykt dotyczyłby tekstu, którego już nie ma.

## Tryb awaryjny: fail-open czy fail-closed

Wtyczka nigdy nie zgaduje werdyktu. Tryb awaryjny rozstrzyga, co zrobić z postem, gdy
werdyktu nie ma:
- brama odpowiedziała `nieocenione` (nie zdążyła ocenić albo nie rozpoznała odpowiedzi);
- werdykt nie nadszedł w czasie oczekiwania;
- brama odrzuciła wysyłkę z powodu błędu konfiguracji (np. zły klucz, profil spoza listy
  klucza, brak zapisanego webhooka) — kod błędu pojawia się w panelu administratora;
- post nie ma tekstu do oceny (np. sam załącznik);
- post był dłuższy niż 3000 znaków, a brama uznała jego początek za bezpieczny.

**fail-closed** (domyślnie) zostawia taki post w kolejce moderacji dla człowieka.
**fail-open** publikuje go bez oceny — a jeśli werdykt przyjdzie później, wtyczka nadal go
zastosuje, dopóki nikt nie ruszył postu: `zablokowane` odsyła post z powrotem do kolejki
moderacji, `ocenzurowane` postępuje według ustawienia „Post ocenzurowany”. Jeśli w tym czasie
moderator zdecydował o poście albo post został edytowany, decyzja człowieka jest ostateczna.

Gdy brama jest chwilowo przeciążona albo niedostępna (lub nie ma połączenia), post czeka:
zadanie ponawia wysyłkę po czasie wskazanym przez bramę albo po coraz dłuższej przerwie
(od 1 minuty do 1 godziny). Jeśli do końca czasu oczekiwania się nie uda — tryb awaryjny.

## Panel administratora

- **Komunikat na każdej stronie panelu**, gdy wtyczka jest włączona, ale nie może działać
  (czego brakuje), albo gdy brama odrzuciła ostatnią wysyłkę z powodu błędu konfiguracji
  (kod błędu i czas). Znika po pierwszej udanej wysyłce.
- **Narzędzia i konserwacja → Dzienniki → Minos — dziennik**: stan konfiguracji, adres
  webhooka, posty wymagające uwagi (zatrzymane w kolejce, czekające na werdykt, opublikowane
  bez oceny w trybie fail-open i oznaczone jako potrzebujące wsparcia) oraz dziennik
  zdarzeń. Dziennik zawiera wyłącznie kody i
  numery postów — nigdy treść, klucz ani sekret. Wpisy starsze niż 30 dni są usuwane.

## Ograniczenia

- Ocenianych jest tylko pierwsze 3000 znaków postu.
- Tytuły odpowiedzi nie są oceniane (MyBB nadaje im zwykle „RE: …”); tytuł nowego wątku —
  tak.
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
- Gdy wtyczka jest dezaktywowana, webhook odpowiada błędem i nie stosuje werdyktów; posty
  już zatrzymane zostają w kolejce moderacji dla ludzi.

## Odinstalowanie

**Konfiguracja → Wtyczki → Odinstaluj** usuwa ustawienia i zadanie. Panel zapyta, czy
usunąć także tabele wtyczki (historię ocen i oryginały ocenzurowanych postów); „Nie”
zostawia je w bazie — ponowna instalacja z nich skorzysta. Potem usuń pliki wymienione
w instrukcji instalacji.

## Licencja

GPL-2.0 (plik `LICENSE`). Dołączona biblioteka `minos-moderation/client-php` jest na
licencji MIT.
