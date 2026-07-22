# vrlv3

Oletuksena tietokannan nimi on vrlv3, serveri localhost ja user root ilman salasanaa.
Kopioi tiedosto fuel/application/config/database_skeleton.php samaan kansioon ja nimeä se pelkäksi database.php:ksi.
Sitten muokkaa se vastaamaan omaa konfiguraatiotasi, mutta ÄLÄ COMMITOI TÄTÄ TIEDOSTOA, vaan laita se .gitignoreen vaikka tortoisegitin avulla!

Oletuksena salausavaimeksi on asetettu $config['encryption_key'] = 'test_test_test_test';
Kopioi tiedosto fuel/application/config/config_skeleton.php samaan kansioon ja nimeä se pelkäksi config.php:ksi.
Sitten voit halutessasi muokata sen vastaamaan omaa konfiguraatiotasi, mutta ÄLÄ COMMITOI TÄTÄ TIEDOSTOA, vaan laita se .gitignoreen vaikka tortoisegitin avulla!

Kuvat, javascripta yms. sijoitetaan assets kansioon.

Alkuunsa luo tietokanta, ja aja sinne database kansion .sql tiedostot seuraavassa järjestyksessä (älä aja muita tiedostoja):
fuel_schema.sql

listat_data_schema.sql

tunnukset_schema.sql

from_scratch_schema.sql

from_scratch_insert.sql

## :whale: Pyörittäminen lokaalisti Dockerilla

Sovellus on kontitettu ja käynnistettävissä docker composella. Paikallisissa `database.php` ja `config.php` tiedostoissa tarvii olla tällaiset arvot:

<details>
<summary>database.php, rivistä 76 -> </summary>

```php
$db['default'] = array(
	'dsn'	=> '',
	'hostname' => 'db',
	'username' => 'root', //EDIT THIS
	'password' => '', //EDIT THIS
	'database' => 'vrlv3', //EDIT THIS
	'dbdriver' => 'mysqli',
	'dbprefix' => '',
	'pconnect' => FALSE,
	'db_debug' => (ENVIRONMENT !== 'production'),
	'cache_on' => FALSE,
	'cachedir' => '',
	'char_set' => 'utf8',
	'dbcollat' => 'utf8_swedish_ci',
	'swap_pre' => '',
	'encrypt' => FALSE,
	'compress' => FALSE,
	'stricton' => FALSE,
	'failover' => array(),
	'save_queries' => TRUE,
	'port' => 3306
);
```

</details>

<details>
<summary>config.php, rivi 26</summary>

```php
$config['base_url'] = 'http://localhost/';
```

</details>

### KOMENNOT

Käynnistys (sama komento toimii kaikilla kerroilla). Sinun ei tarvitse käynnistää containeria uudestaan kun teet koodiin muutoksia, ne päivittyvät reaaliajassa.

```sh
docker compose up --build
```

Containerien sammutus: `ctrl + C` tai pidemmän kaavan kautta (poistaa myös tietokannan volumen = alustuu seuraavalla käynnistyskerralla "nollasta"):

```sh
docker compose down --volumes
```

#### Oletusosoitteet yms. Dockerilla käynnistettäessä:

Verkko-osoite: `http://localhost`

PHPMyAdmin: `http://localhost:8080`

### Anonymisoidun tuotantodumpin tuonti

Tuotannosta anonymisoitu dumppi (esim. `vrl_prod_dump_072026_anonymized.sql`, ks. `anonymize_dump.py`) tuodaan `db`-containeriin seuraavasti. Kontin sisällä oleva tietokanta on nimeltään `mariadb`.

1. Kopioi dumppi containerin sisään (nopeampaa ja luotettavampaa kuin putkittaa iso tiedosto suoraan `docker exec`:n läpi):

```sh
docker cp vrl_prod_dump_072026_anonymized.sql db:/tmp/dump.sql
```

2. (Valinnainen) Nollaa kohdekanta, jos siellä on jo dataa jota ei haluta säilyttää:

```sh
docker exec db mariadb -u root -e "DROP DATABASE IF EXISTS vrlv3; CREATE DATABASE vrlv3;"
```

3. Tuo dumppi:

```sh
docker exec -i db sh -c "exec mariadb -u root vrlv3 < /tmp/dump.sql"
```

4. Siivoa väliaikaistiedosto pois containerista:

```sh
docker exec db rm /tmp/dump.sql
```

Ei erillisiä tunnuksia tarvita: root-käyttäjällä on tyhjä salasana ja tietokanta on nimeltään `vrlv3` (ks. `docker-compose.yml`).

### Käyttäjän salasanan vaihtaminen manuaalisesti

Anonymisoitu dumppi korvaa kaikki salasanat samalla roska-hashilla, joten kirjautumista varten pitää asettaa oma salasana manuaalisesti. Sovellus käyttää PHP:n natiivia `password_hash()`/`password_verify()`-mekanismia (`fuel/application/models/Ion_auth_model.php`), joten hash pitää generoida samalla PHP-versiolla kuin sovellus ajaa:

1. Generoi bcrypt-hash halutulle salasanalle apache-containerissa:

```sh
docker exec php-apache php -r "echo password_hash('haluttu_salasana', PASSWORD_DEFAULT), PHP_EOL;"
```

2. Päivitä hash tietokantaan käyttäen heredocia (jottei shell tulkitse hashin `$`-merkkejä muuttujina):

```sh
docker exec -i db mariadb -u root vrlv3 <<'EOF'
UPDATE vrlv3_tunnukset SET password='<hash_tähän>' WHERE tunnus=406;
EOF
```

`tunnus`-sarake on numeerinen (`int(5) unsigned zerofill`), joten hae käyttäjä ilman etunollia (`tunnus=406`, ei `'00406'`) — zerofill vaikuttaa vain näyttömuotoon, ei tallennettuun arvoon. Vaihtoehtoisesti voi hakea `email`-sarakkeen perusteella. `salt`-sarakkeeseen ei tarvitse koskea, koska nykyaikainen bcrypt-hash on itsenäinen.

# FUEL CMS

FUEL CMS is a [CodeIgniter](https://codeigniter.com) based content management system. To learn more about its features visit: http://www.getfuelcms.com

### Installation

To install FUEL CMS, copy the contents of this folder to a web accessible folder and browse to the index.php file. Next, follow the directions on the screen.

### Upgrade

If you have a current installation and are wanting to upgrade, there are a few things to be aware of. FUEL 1.4 uses CodeIgniter 3.x which includes a number of changes, the most prominent being the capitalization of controller and model names. Additionally it is more strict on reporting errors. FUEL 1.4 includes a script to help automate most (and maybe all) of the updates that may be required in your own fuel/application and installed advanced module code. It is recommended you run the following command using a different branch to test if you are running on Mac OSX or a Unix flavor operating system and using Git:
`php index.php fuel/installer/update`

### Documentation

To access the documentation, you can visit it [here](http://docs.getfuelcms.com).

### Bugs

To file a bug report, go to the [issues](http://github.com/daylightstudio/FUEL-CMS/issues) page.

### License

FUEL CMS is licensed under [Apache 2](http://www.apache.org/licenses/LICENSE-2.0.html). The full text of the license can be found in the fuel/licenses/fuel_license.txt file.

---

**Developed by David McReynolds, of [Daylight Studio](http://www.thedaylightstudio.com/)**
