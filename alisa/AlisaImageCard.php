<?php
/**
 * 
 */

namespace bot\alisa;

use bot\components\Text;
use bot\components\standard\Image;

/**
 * Class AlisaImageCard
 *
 * @property bool $isBigImage    - отобразится большая картинка
 * @property string $imageId     - идентификатор картинки
 * @property array $button       - кнопка для картинки
 *
 * @property string $skillId - идентификатор навыка
 *
 * @property bool $isItemsList   - отображение коллекции
 * @property bool $isItemsImage  - в коллекции есть картинки
 * @property string $footerText  - текст внизу картинки
 * @property array $footerButton - кнопки внизу коллекции
 *
 */
class AlisaImageCard extends Image
{
    const MAX_IMAGE_FOR_GALLERY = 5;
    public $isBigImage;
    public $imageId;
    public $button;

    private $skillId;

    public $isItemsList;
    public $isItemsImage = true;
    public $footerText;
    public $footerButton;

    public function __construct($token = null)
    {
        $this->setImageToken($token);
        $this->isItemsImage = true;
        $this->isBigImage = false;
        $this->isItemsList = false;
        $this->footerButton = null;
        $this->button = null;
        parent::__construct();
    }

    /**
     * Установить идентификатор навыка
     *
     * Нужен для того чтобы можно было корректно загружать картинки
     *
     * @param $skillId
     */
    public function setSkillId($skillId)
    {
        $this->skillId = $skillId;
    }

    private $imageToken;

    /**
     * Установить токен для картинок
     * @param string $token
     */
    public function setImageToken($token = '<Ваш токен>')
    {
        $this->imageToken = $token;
    }

    /**
     * Получить объект для отображения кнопки
     * @param null $button
     * @param bool $isUrl
     * @return array|null
     */
    protected function getButton($button = null, $isUrl = true)
    {
        if ($button == null) {
            $button = $this->button;
        }

        if (!is_array($button) && $button !== null) {
            $button = ['text' => $button, 'payload' => $button];
        } elseif (!is_array($button)) {
            return null;
        }

        $payload = $button['payload'] ?? null;
        $text = $button['text'] ?? null;
        $url = $button['url'] ?? null;

        $btn = [];
        if ($payload !== null) {
            $btn['payload'] = $payload;
        }
        if ($text === null) {
            return null;
        } else {
            $btn['text'] = Text::resize($text, 65);
        }

        if ($url) {
            if (strpos($url, 'utm_source') === false) {
                if (strpos($url, '?') !== false) {
                    $url .= '&';
                } else {
                    $url .= '?';
                }
                $url .= 'utm_source=Yandex_Alisa&utm_medium=cpc&utm_campaign=phone';
            }
            $btn['url'] = Text::resize($url, 1024);
        } else {
            if ($isUrl && $payload === null) {
                return null;
            }
        }

        return $btn;
    }

    /**
     * Проверяет наличие картинки в бд, и в случае отсутствия загружает её на сервер Яндекса и записывает данные в бд
     * Если не удалось загрузить картинку, тогда trigger равен false, и картинка не отобразится.
     * Возвращает массив
     *
     * status - статус загрузки картинки;
     *     - true - успешно загружено;
     *     - false - не удалось загрузить;
     * img_id - Идентификатор картинки в системе яндекс
     *
     * @param string $host - Адресс ресурса от куда используется картинка. Актуально, если все картинки с одного ресурса.
     * Например вы загружаете картинки с ресурса example.com, тогда вы просто напросто можете указать host = example.com/
     * И отправлять картинки в виде img.png, вместо example.com/img.png
     *
     * @return array['status'=>bool,'img_id'=>string]
     */
    protected function getImageId($host = 'https://www.islandgift.ru/'): array
    {
        if ($this->imageToken == null) {
            $this->setImageToken();
        }
        /**
         * todo Тут желательно вставить свой поиск в базе данных, но можно оставить и так.
         */
        $imageDir = __DIR__ . '/images';
        if (!is_dir($imageDir)) {
            mkdir($imageDir);
        }
        $imageFileJson = $imageDir . '/imageData.json';
        $imageFile = fopen($imageFileJson, 'r');
        $alisaImages = [];
        if ($imageFile) {
            $alisaImages = json_decode(fread($imageFile, filesize($imageFileJson)), true);
            fclose($imageFile);
        }
        $trigger = true;
        $imgId = $host . str_replace('../', '', $this->imgDir);
        if (!$alisaImages[$imgId] ?? null) {
            require_once __DIR__ . '/../api/YandexImages.php';
            $yImages = new \bot\api\YandexImages();
            $yImages->setImageToken($this->imageToken);
            $yImages->skillsId = $this->skillId;
            $image = $yImages->downloadImageUrl($host . str_replace('../', '', $this->imgDir));
            if ($image) {
                $alisaImages[$imgId] = $image['id'];
                $imageFile = fopen($imageFileJson, 'w');
                fwrite($imageFile, json_encode($alisaImages, JSON_UNESCAPED_UNICODE));
                fclose($imageFile);
            } else {
                $trigger = false;
            }
        }
        return ['status' => $trigger, 'img_id' => $alisaImages[$imgId]];
    }

    /**
     * Получить контент для большой картинки
     * @param $host
     * @return array|null
     */
    public function sendBigImage($host)
    {
        $res = $this->getImageId($host);
        if ($res['status']) {
            $this->imageId = $res['img_id'];
            return $this->getBigImage();
        }
        return null;
    }

    /**
     * Отображение галлереи из картинок.
     * Максимум 5 изображений
     *
     * Изменяет переменную sendImage
     * Если переменная равно '', значит картинки подгрузить не удалось,
     * И список отображаться не будут
     *
     * @param $host
     *
     * @return array|null
     * @throws \Exception
     */
    public function sendItemsList($host)
    {
        if ($this->isItemsImage) {
            $index = 0;
            $tmp = [];
            foreach ($this->imagesList as $image) {
                $this->imgDir = $image['image_dir'];
                if ($this->imgDir != '' || $this->imgDir != null) {
                    $res = $this->getImageId($host);
                    if ($res['status']) {
                        if ($index >= self::MAX_IMAGE_FOR_GALLERY) {
                            break;
                        }
                        unset($image['image_dir']);
                        $image['image_id'] = $res['img_id'];
                        $tmp[] = $image;
                        $index++;
                    } else {
                        throw new \Exception('AlisaImageCard::sendItemsList() Error: Не удалось получить картинку. Url: ' . $host);
                    }
                } else {
                    if ($index >= self::MAX_IMAGE_FOR_GALLERY) {
                        break;
                    }
                    unset($image['image_dir']);
                    $tmp[] = $image;
                    $index++;
                }
            }
            $this->imagesList = $tmp;
            if ($index) {
                return $this->getItemList();
            }
        } else {
            $tmp = [];
            $index = 0;
            foreach ($this->imagesList as $image) {
                unset($image['image_dir']);
                if ($index >= self::MAX_IMAGE_FOR_GALLERY) {
                    break;
                }
                $index++;
                $tmp[] = $image;
            }
            $this->imagesList = $tmp;
            return $this->getItemList();
        }
        return null;
    }

    /**
     * Получить данные для большой картинки
     *
     * @return array
     */
    public function getBigImage()
    {
        if ($this->button && (!is_array($this->button))) {
            $this->button = ['text' => $this->button, 'payload' => $this->button];
        }

        $btn = $this->getButton();
        $data = [
            'type' => 'BigImage',
            "image_id" => $this->imageId,
            "title" => Text::resize($this->title, 100),
            "description" => Text::resize($this->description, 200),
        ];
        if ($btn) {
            $data['button'] = $btn;
        }
        return $data;
    }

    /**
     * Получить данные для коллекции
     *
     * @return array
     */
    public function getItemList()
    {
        $data = [
            'type' => 'ItemsList',
            "header" => ['text' => Text::resize($this->title, 100)]
        ];

        $data['items'] = $this->imagesList;
        if ($this->footerText) {
            $data['footer'] = ['text' => $this->footerText];
            $button = $this->getButton($this->footerButton, false);
            if ($button !== null) {
                $data['footer']['button'] = $button;
            }
        }
        return $data;
    }

    /**
     * Функция вернет массив для кнопки, которая необходима для карточек и списков.
     *
     * @param $text - Текст для пнопки
     * @param null $url - Ссылка, на сайт
     * @param null $payload - Произвольный json
     *
     * @return array['text'=>string, 'url'=>'string',  'payload'=>json]
     */
    public function addButtons($text, $url = null, $payload = null)
    {
        return [
            'text' => $text,
            'url' => $url,
            'payload' => $payload
        ];
    }

    /**
     * Добавить картинку в коллекцию
     *
     * @param string $imgDir
     * @param string $title
     * @param string $description
     * @param null|string|array $button - если null, то кнопка не отображается. Если string, тогда поля text и payload, заполняются содержимым переменной.
     * Если array то ввида:
     * [
     *  'text' => string
     *  'url' => string
     *  'payload' => string
     * ]
     */
    public function addImages($imgDir, $title, $description, $button = null)
    {
        $data = $this->addImagesList($imgDir, Text::resize($title, 100), Text::resize($description, 250));
        $button = $this->getButton($button, false);
        if ($button !== null) {
            $data['button'] = $button;
        }
        $this->imagesList[] = $data;
    }
}