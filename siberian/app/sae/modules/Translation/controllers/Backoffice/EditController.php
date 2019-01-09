<?php

use Gettext\Translation;
use Gettext\Translations;

/**
 * Class Translation_Backoffice_EditController
 */
class Translation_Backoffice_EditController extends Backoffice_Controller_Default
{
    /**
     * @var
     */
    protected $_xml_files;

    /**
     * Default tree
     */
    public function loadAction()
    {
        $payload = [
            "title" => sprintf("%s > %s",
                __("Settings"),
                __("Translations")),
            "icon" => "fa-language",
        ];

        $this->_sendJson($payload);
    }

    public function findAction()
    {
        try {
            $request = $this->getRequest();
            $sectionTitle = "";
            $isEdit = true;

            $langId = $request->getParam("langId", null);
            if (empty($langId)) {
                $sectionTitle = __("Create a new language");
                $isEdit = false;
            } else {
                $langId = base64_decode($langId);
                $langId = explode("_", strtolower($langId));
                if (count($langId) == 2) {
                    $langId[1] = strtoupper($langId[1]);
                }
                $langId = implode("_", $langId);
                $sectionTitle = __("Edit the language: %s",
                    Core_Model_Language::getLanguage($langId)->getName());
            }

            $countryCode = $langId;

            $locale = Zend_Registry::get("Zend_Locale");
            $languages = $locale->getTranslationList("language");
            $existingLanguages = Core_Model_Language::getLanguageCodes();
            foreach ($languages as $k => $language) {
                if (!$locale->isLocale($k) || in_array($k, $existingLanguages)) {
                    unset($languages[$k]);
                }
            }

            asort($languages, SORT_LOCALE_STRING);

            // Parsing .mo base files!
            $translations = $this->parseTranslations($langId);

            // Available files
            $files = [];
            foreach (array_keys($translations) as $file) {
                $files[$file] = ucfirst(basename(basename($file, ".csv"), ".mo"));
            }

            $payload = [
                "success" => true,
                "section_title" => $sectionTitle,
                "is_edit" => $isEdit,
                "country_code" => $countryCode,
                "country_codes" => $languages,
                "translation_files" => $files,
                "translations" => $translations,
            ];
        } catch (\Exception $e) {
            $payload = [
                "error" => true,
                "message" => $e->getMessage(),
            ];
        }

        $this->_sendJson($payload);
    }

    /**
     *
     */
    public function saveAction()
    {
        try {
            $request = $this->getRequest();
            $data = $request->getBodyParams();

            if (__getConfig("is_demo")) {
                // Demo version
                throw new \Siberian\Exception(__("You cannot change translation, this is a demo version."));
            }

            if (empty($data)) {
                throw new \Siberian\Exception(__("Missing data, unable to save!"));
            }

            $base_path = Core_Model_Directory::getBasePathTo("languages/");
            $countryCode = $data["country_code"];
            $translationDir = $base_path . $countryCode;
            $translationFile = $data["file"];
            $translationData = $data["collection"];
            ksort($translationData);

            if (empty($countryCode)) {
                throw new \Siberian\Exception(__("Please, choose a language."));
            }
            if (empty($translationFile)) {
                throw new \Siberian\Exception(__("Please, choose a file."));
            }

            if (!is_dir($translationDir)) {
                mkdir($translationDir);
            }

            // Yeah!
            $translations = new Translations();
            foreach ($translationData as $key => $value) {
                $tmp = new Translation(null, $key);
                $tmp->setTranslation($value);

                $translations[] = $tmp;
            }
            $extension = pathinfo($translationFile, PATHINFO_EXTENSION);

            switch ($extension) {
                case "mo":
                    $translations->toMoFile("{$translationDir}/$translationFile");
                    break;
                case "csv":
                    $translations->toCsvDictionaryFile("{$translationDir}/$translationFile");
                    break;
            }

            # Clean "*_translation" cache tags
            $this->cache->clean(
                Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG,
                [
                    "mobile_translation"
                ]
            );

            $payload = [
                "success" => true,
                "message" => __("Language successfully saved"),
            ];
        } catch (\Exception $e) {
            $payload = [
                "error" => true,
                "message" => $e->getMessage(),
            ];
        }

        $this->_sendJson($payload);
    }

    /**
     * @param $langId
     * @return array
     * @throws Zend_Translate_Exception
     */
    public function parseTranslations($langId)
    {
        $translations = [];
        $userTranslationsDirectory = Core_Model_Directory::getBasePathTo("languages/{$langId}/");

        $cachedFiles = Siberian_Cache_Translation::getCache();

        // Fetching all keys!
        foreach ($cachedFiles["base"] as $filename => $path) {
            if (!is_file($path)) {
                continue;
            }

            $pathinfo = pathinfo($path);
            $type = $pathinfo["extension"];
            $fileBase = basename($filename, ".{$type}");
            if (!in_array($type, ["csv", "mo"])) {
                continue;
            }

            // Easy
            $tmpTranslationData = $this->parseType([], $filename, $path, $type);

            // Default translation (if exists) mixed csv/mo, mo being more recent!
            $defaultTranslationCSV = $cachedFiles[$langId]["{$fileBase}.csv"];
            if (is_file($defaultTranslationCSV)) {
                $tmpTranslationData = $this->parseType($tmpTranslationData, $filename, $defaultTranslationCSV, "csv");
            }
            $defaultTranslationMO = $cachedFiles[$langId]["{$fileBase}.mo"];
            if (is_file($defaultTranslationMO)) {
                $tmpTranslationData = $this->parseType($tmpTranslationData, $filename, $defaultTranslationMO, "mo");
            }

            // User translations (if exists)!
            $userTranslationCSV = $userTranslationsDirectory . $fileBase . ".csv";
            $userTranslationMO = $userTranslationsDirectory . $fileBase . ".mo";

            if (is_file($userTranslationMO)) {
                $tmpTranslationData = $this->parseType($tmpTranslationData, $filename, $userTranslationMO, "mo");
            } else if (is_file($userTranslationCSV)) {
                $tmpTranslationData = $this->parseType($tmpTranslationData, $filename, $userTranslationCSV, "csv");
            }

            if (!empty($tmpTranslationData)) {
                $translations = array_merge($translations, $tmpTranslationData);
            }
        }

        return $translations;
    }

    /**
     * @param $tmpTranslationData
     * @param $filename
     * @param $path
     * @param $type
     * @return mixed
     * @throws Zend_Translate_Exception
     */
    public function parseType($tmpTranslationData, $filename, $path, $type)
    {
        // Gettext / CSV selector! MsgID!
        if (!array_key_exists($filename, $tmpTranslationData)) {
            $tmpTranslationData[$filename] = [];
        }

        switch ($type) {
            case "csv":
                $csvResource = fopen($path, "r");
                while ($line = fgetcsv($csvResource, 1024, ";", '"')) {
                    $key = str_replace('\"', '"', $line[0]);
                    $tmpTranslationData[$filename][$key] = null;
                    if (isset($line[1])) {
                        $tmpTranslationData[$filename][$key] = str_replace('\"', '"', $line[1]);
                    }

                }
                fclose($csvResource);

                break;
            case "mo":
                /**
                 * @var $translator Zend_Translate_Adapter_Gettext
                 */
                $translator = new \Zend_Translate([
                    "adapter" => "gettext",
                    "content" => $path,
                    "locale" => "en"
                ]);
                $_tmp = $translator->getData("en");
                foreach ($_tmp as $key => $value) {
                    $key = str_replace('\"', '"', $key);
                    $tmpTranslationData[$filename][$key] = $value;
                }

                break;
        }

        return $tmpTranslationData;
    }

    /**
     * @param $resource
     * @param $data
     */
    protected function _putCsv($resource, $data)
    {
        $enclosure = '"';
        $separator = ';';
        $br = "\n";

        // Fix reverse addcslashes, and re-add them just in case of!
        $key = addcslashes(str_replace('\"', '"', $data[0]), '"');
        $value = addcslashes(str_replace('\"', '"', $data[1]), '"');

        $str = [
            $enclosure,
            $key,
            $enclosure,
            $separator,
            $enclosure,
            $value,
            $enclosure,
            $br
        ];

        fputs($resource, join("", $str));
    }

    /**
     *
     */
    public function translateAction()
    {
        $api = Api_Model_Key::findKeysFor("yandex");
        $yandex_key = $api->getApiKey();

        $data = Siberian_Json::decode($this->getRequest()->getRawBody());


        /** Caching */
        $translation = new Cache_Model_Translation();
        $translation = $translation->find([
            "target" => $data["target"],
            "text" => $data["text"],
        ]);

        if ($translation->getId()) {
            $html = [
                "success" => 1,
                "from_cache" => 1,
                "result" => ["text" => [$translation->getTranslation()]],
            ];
        } else {
            if (empty($yandex_key)) {
                $html = [
                    "error" => 1,
                    "message" => __("#734-01: Missing yandex API key"),
                ];
            } else {
                $url = "https://translate.yandex.net/api/v1.5/tr.json/translate?key=" . $yandex_key . "&text=%TEXT%&lang=en-%TARGET%";
                $url = str_replace("%TEXT%", urlencode($data["text"]), $url);
                $url = str_replace("%TARGET%", urlencode($data["target"]), $url);

                $response = Siberian_Json::decode(file_get_contents($url));

                if (isset($response["code"]) && $response["code"] == "200") {
                    $translation
                        ->setTarget($data["target"])
                        ->setText($data["text"])
                        ->setTranslation($response["text"][0])
                        ->save();

                    $html = [
                        "succes" => 1,
                        "result" => $response,
                    ];
                } else {
                    /** Try with google */
                    $url = "https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl=%TARGET%&dt=t&q=%TEXT%";
                    $url = str_replace("%TEXT%", urlencode($data["text"]), $url);
                    $url = str_replace("%TARGET%", urlencode($data["target"]), $url);

                    $response = Siberian_Json::decode(file_get_contents($url));
                    $result = $response[0][0][0];

                    if (!empty($result)) {
                        $translation
                            ->setTarget($data["target"])
                            ->setText($data["text"])
                            ->setTranslation($result)
                            ->save();

                        $html = [
                            "succes" => 1,
                            "result" => ["text" => [$result]],
                        ];
                    } else {
                        $html = [
                            "error" => 1,
                            "message" => (isset($response["message"])) ? "#734-02: " . __($response["message"]) : __("#734-03: Invalid yandex API key OR Free limit request exceeded."),
                        ];
                    }

                }

            }
        }

        $this->_sendHtml($html);
    }

}
