<?php
/**
 * 
 */

namespace bot\alisa;

/**
 * Класс для взаимодействия в различными блокими для Алисы
 * - Картинки
 * - nlu
 * - Кнопки
 * - Звуки
 *
 * Class Alisa
 * @property AlisaNlu $nlu - nlu см. Документацию; Возможности навыков -> Именованные сущности в запросах.
 * @property AlisaImageCard $image - Класс для работы с картинками, а именно отображение карточек и списков.
 * @property AlisaButtons $buttonBlock - Класс для работы с кнопками.
 * @property AlisaSound $sound - Класс для работы со звуками;
 */
class Alisa
{
    public $nlu;
    public $image;
    public $sound;
    public $buttonBlock;

    public function __construct($imageToken)
    {
        $this->nlu = new AlisaNlu();
        $this->sound = new AlisaSound();
        $this->buttonBlock = new AlisaButtons();
        $this->image = new AlisaImageCard($imageToken);
    }

    /**
     * Проверка что есть картинка или галлерея
     *
     * @return bool
     */
    final public function getIsImage(): bool
    {
        return ($this->image->isBigImage || $this->image->isItemsList);
    }

    /**
     * Получить массив для отображения картинки или галлереи
     *
     * @param $host - url от куда было взято изображение.
     *
     * @return array|null
     * @throws \Exception
     */
    final public function getImage($host)
    {
        $sendImage = null;
        if ($this->getIsImage()) {
            if ($this->image->isItemsList) {
                $sendImage = $this->image->sendItemsList($host);
                if ($sendImage) {
                    return $sendImage;
                }
            }
            if ($this->image->isBigImage) {
                $this->image->description = $this->getSound($this->image->description, false);
                $sendImage = $this->image->sendBigImage($host);
            }
        }
        return $sendImage;
    }

    /**
     * Добавить кнопку в виде кнопки
     *
     * @param $title - Название кнопки
     * @param null $url - Название кнопки
     * @param null $payload - Произвольный json
     */
    final function addButtons($title, $url = null, $payload = null): void
    {
        $this->buttonBlock->setBtn($title, $url, $payload);
    }

    /**
     * Добавить кнопку в виде ссылки
     *
     * @param $title - Название кнопки
     * @param null $url - Название кнопки
     * @param null $payload - Произвольный json
     */
    final function addLinks($title, $url = null, $payload = null): void
    {
        $this->buttonBlock->setLink($title, $url, $payload);
    }

    /**
     * Инициализация кнопок
     *
     * @param $title - Название кнопки
     * @param null $url - Название кнопки
     * @param null $payload - Произвольный json
     * @param bool $type - Тип кнопки
     */
    final public function initButtons($title, $url = null, $payload = null, $type = AlisaButtons::B_BTN): void
    {
        $this->buttonBlock->clearButtons();
        switch ($type) {
            case AlisaButtons::B_BTN:
                $this->buttonBlock->setBtn($title, $url, $payload);
                break;
            case AlisaButtons::B_LINK:
                $this->buttonBlock->setLink($title, $url, $payload);
                break;
        }
    }

    /**
     * Получить данные для кнопок
     *
     * @return array|null
     */
    final public function getButtons()
    {
        return $this->buttonBlock->getButtons();
    }

    /**
     * Переопределение обработки звуков.
     * В логике навыка, вы просто можете переинициализировать данный метод, указав свои звуки которые вам необходимы.
     *
     * @param $text - Текст ответа навыка
     * @param $isShowSound - Отображать или нет звуки
     * @param null $customParams - Кастомные параметры звука должен быть массивом вида:
     * [
     *  [
     *      'key' => string,  - Ключ, который будет вставлен в текст, и в дальнейшем заменен на звук
     *      'sounds' => array - Массив звуков. Из них выберется рандомный.
     *  ]
     * ]
     *
     * @return string|null
     */
    public function getSound($text, $isShowSound, $customParams = null)
    {
        if ($customParams == null && (strpos($text, '#$Sound$#') !== false)) {
            $customParams = [
                [
                    'key' => '#$Sound$#',
                    'sounds' => [
                        '<speaker audio=\"alice-sounds-game-win-1.opus\">',
                        '<speaker audio=\"alice-sounds-game-win-2.opus\">',
                        '<speaker audio=\"alice-sounds-game-win-3.opus\">',
                        '<speaker audio=\"alice-sounds-game-8-bit-coin-1.opus\">',
                        '<speaker audio=\"alice-sounds-game-8-bit-coin-2.opus\">',
                        '<speaker audio=\"alice-sounds-game-8-bit-phone-1.opus\">',
                        '<speaker audio=\"alice-sounds-game-boot-1.opus\">',
                    ]
                ]
            ];
        }

        return $this->sound->getSound($text, $isShowSound, $customParams);
    }

    /**
     * Установить значение nlu
     *
     * @param $nlu
     */
    final public function setNlu($nlu): void
    {
        $this->nlu->nlu = $nlu;
    }

    /**
     * Обработка nlu
     * По умолчанию идет обработка и поиск имени
     * По необходимости можно переинициализировать.
     *
     * А если совсем не нужно, то просто возвращайте массив:
     * [
     *  'status' => false,
     *  'result' => ''
     * ]
     *
     * @param $nlu
     *
     * @return array
     */
    public function nluGenerate($nlu = null): array
    {
        if ($nlu) {
            $this->setNlu($nlu);
        }
        return $this->nlu->getUserName($this->nlu->getFio()['result']);
    }
}