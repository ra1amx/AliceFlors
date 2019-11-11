<?php

namespace bot\kernel;
ini_set('display_errors', 'on');
header('Content-Type: application/json');

use bot\components\Text;

/**
 * Класс для работы с Яндекс Алиса
 * Class YandexBot
 * @property string $IMAGE_TOKEN - Токен для загрузки картинок
 * @property string $ttsMessage  - Звуковое преобразование текста
 * @property array $buttons      - Массив стандартных кнопок (инициализируется при создании класса)
 * @property string $skillId     - Идентификатор навыка
 * @property bool $screen        - Тригер наличия экрана. Понимает каким устройством запущен навык.
 * @property $output             - Тело запроса
 * @property $nlu                - Обработчик имен адресов и тд.
 * @property $meta               - Мета данные по навыку
 * @property string $userId      - Идентификатор сессии
 * @property string $sessionId   - Идентификатор пользователя
 */
class YandexAlisa extends Bot
{
    // ========= data =======================================================
    private const VERSION = '1.0';
    public $output = null;      // Параметры запроса
    private $ttsMessage;        // Голосовой ответ навыка
    public $sessionId;          // Идентификатор сессии
    private $buttons;           // Отображение кнопок
    public $skillId;            // Идентификатор навыка
    private $screen;            // Наличие экрана
    public $userId;             // Идентификатор пользователя
    public $meta;               // Мета данные по нывыку
    public $nlu;                // Дополнительные параметры яндекса

    // ======== function ====================================================

    public function __construct()
    {
        $this->output = file_get_contents('php://input');
        $this->ttsMessage = null;
        $this->screen = true;
    }

    /**
     * Устанавливаем стандартные кнопки
     * Отображаюся всегда, если не установлены стандартные кнопки
     *
     * На вход получает массив из кнопок (['кнопка 1',...,'кнопка n'])
     *
     * @param array $button
     */
    public function setButtons($button): void
    {
        if (is_array($button)) {
            $this->buttons = $button;
            if ($this->processingCommand !== null) {
                $this->processingCommand->buttons = $button;
            }
        } else {
            $this->buttons = null;
        }
    }

    /**
     * Отправка сообщения
     * Возвращается json строка.
     *
     * @param bool $endSession - открыта сессия или нет (вышел пользователь или нет)
     *
     * @return string
     * @throws \Exception
     */
    public function sendMessage($endSession = true): string
    {
        if (!$this->ttsMessage) {
            $this->ttsMessage = $this->textMessage;
        }

        $result = [];
        $result['response'] = $this->getResponse($endSession);
        $result['session'] = $this->getSession();
        $result['version'] = self::VERSION;

        if ($this->isLog) {
            $this->botParamsJson = $this->processingCommand->param;
            $this->saveCommand();
        }

        return json_encode($result);
    }

    /**
     * Возвращает тело ответа.
     * Именно тут происходит обработка и вставка текстов
     * и заполнение данных(кнопки, ссылки, картинки, ...)
     *
     * @param bool $endSession
     *
     * @return array
     * @throws \Exception
     */
    private function getResponse($endSession): array
    {
        $response = [];
        $response['text'] = Text::resize($this->processingCommand->getSound($this->textMessage, false));
        $response['tts'] = Text::resize($this->processingCommand->getSound($this->processingCommand->generateTTS($this->ttsMessage), true));

        /**
         * Проверка на то какое устройство используется
         * С экраном или нет.
         *
         * Если устройство без экрана, тогда кнопки и картинки не отображаются
         */
        if ($this->screen) {

            $image = $this->processingCommand->getImage($this->processingCommand->getHost());
            if ($image) {
                $response['card'] = $image;
            }
            $buttons = $this->processingCommand->getButtons();
            if ($buttons === null) {
                $this->processingCommand->addButtons($this->buttons);
                $this->processingCommand->addLinks($this->buttonMessage, $this->urlMessage);
                $buttons = $this->processingCommand->getButtons();
            }
            if ($buttons) {
                $response['buttons'] = $buttons;
            }
        }

        $response['end_session'] = $endSession;
        return $response;
    }

    /**
     * Возвращает информацию о сессии
     *
     * @return array
     */
    private function getSession(): array
    {
        return [
            'session_id' => $this->sessionId,
            'message_id' => $this->messageId,
            'skill_id' => $this->skillId,
            'user_id' => $this->userId,
        ];
    }

    /**
     * Инициализация основных параметров.
     * текст запроса
     * сессия и тд.
     *
     * @throws \Exception
     */
    private function initParam(): void
    {
        $this->output = json_decode($this->output, true);
        if (!isset($this->output['session'], $this->output['request'])) {
            throw new \Exception('YandexBot::initParam(): Не корректный output файл!');
        }

        $this->output['request']['command'] = trim(str_replace('  ', ' ', $this->output['request']['command'] ?? ''));
        $this->commandText = mb_strtolower($this->output['request']['command']);
        $this->commandTextFull = mb_strtolower($this->output['request']['original_utterance'] ?? '');

        $this->messageId = $this->output['session']['message_id'] ?? '';
        $this->skillId = $this->output['session']['skill_id'] ?? '';
        $this->userId = $this->output['session']['user_id'] ?? '';
        $this->clientKey = $this->userId;
        $this->sessionId = $this->output['session']['session_id'] ?? '';
        $this->nlu = $this->output['request']['nlu'] ?? [];
        $this->meta = $this->output['meta'] ?? [];

        if (isset($this->meta['interfaces']['screen'])) {
            $this->screen = true;
        } else {
            $this->screen = false;
        }

        $this->name = $this->getName();

        $this->getProcessingCommand();
        $this->processingCommand->image->setSkillId($this->skillId);
        $this->processingCommand->sound->setSkillId($this->skillId);
        $this->processingCommand->meta = $this->meta;
        if (!$this->commandTextFull) {
            if (($this->output['request']['payload'] ?? null)) {
                $data = $this->output['request']['payload'];
                $this->processingCommand->payload = $data;
                if (is_array($data)) {
                    $data = $data['text'] ?? '';
                }
                $this->commandTextFull = mb_strtolower($data);
            }
        }
        $this->processingCommand->origText = $this->output['request']['command'];
        $this->processingCommand->buttons = $this->buttons;
        if (!$this->commandText) {
            $this->commandText = $this->commandTextFull;
            $this->processingCommand->origText = ($this->output['request']['original_utterance'] ?? $this->commandTextFull);
        }
    }

    /**
     * Дополнительная обработка, а именно:
     * - Дополнение или изменение текста
     * - Обработка по картинкам
     * - Обработка типа устройства(с экраном или без)
     * Передается ключ ответа.
     *
     * @param string $key
     */
    protected function dopProcessing($key): void
    {
        if ($this->processingCommand->updateLink) {
            if ($key == 'command') {
                $key = $this->keyCommand;
            }
            $param = $this->processingCommand->getUpdateLink($key, $this->textMessage, $this->buttonMessage, $this->urlMessage);
            $this->textMessage = $param[0];
            $this->buttonMessage = $param[1];
            $this->urlMessage = $param[2];
        }
        /**
         * Проверка устройство с экраном используется или нет
         * если есть экран, то отображаются ссылки, кнопки и картинки
         * в противном случае отображается только текст
         */
        if ($this->screen) {
            /**
             * Проверяем, разрешено ли навыку дополнять изначальный ответ
             * удобно когда надо на стандартную команду переназначить стандартные кнопки
             * или когда необходимо добавить или изменить какой-то определенный текс стандартной команды.
             * (Пока что я использую для рекламы и переназначения кнопок)
             **/

            if ($this->processingCommand->buttons || $this->processingCommand->buttons === null) {
                $this->buttons = $this->processingCommand->buttons;
            }
        } else {
            $this->buttonMessage = '';
            $this->urlMessage = '';
            $this->buttons = null;
        }
    }

    /**
     * Получаем идентификатор команды
     *
     * @return string
     */
    private function getKey(): string
    {
        $this->processingCommand->setNlu($this->nlu);
        $res = $this->processingCommand->nluGenerate();
        if ($res['status'] == false) {
            $key = $this->start();
        } else {
            $key = 'command';
            $text = $this->processingCommand->undefinedText($res['result'], 'name', $this->commandTextFull);
            if ($text) {
                $this->param = $text;
            } else {
                $key = 'goodName';
            }
        }
        return $key;
    }

    /**
     * Получение имени бота.
     * Необходимо если к одному и тому же обработчику ссылается несколько навыков
     *
     * В случае если несколько навыков использует 1 обработчик, тогда name должен быть массивом следующего вида:
     * [
     *      0 => [
     *              'skill_id' => '...',
     *              'name' => '...'
     *           ],
     *
     *      ...
     *
     *      n => [
     *              'skill_id' => '...',
     *              'name' => '...'
     *           ],
     * ]
     *
     * @return string
     */
    protected function getName(): string
    {
        if (is_array($this->name)) {
            if (isset($this->name[0]['skill_id'], $this->name[0]['name'])) {
                $namesArray = $this->name;
                $this->name = $this->name[0]['name'];
                foreach ($namesArray as $value) {
                    if ($value['skill_id'] == $this->skillId) {
                        $this->name = $value['name'];
                        break;
                    }
                }
            } else {
                $this->name = 'standardBotName';
            }
        }
        return $this->name;
    }

    /**
     * Запуск Алисы
     *
     * Можно закинуть свой output идентичный с тем, который отправляет яндекс.
     * Используется параметр исключительно для автоматического тестирования
     * Возвращает тело ответа.
     *
     * @param null $output
     *
     * @return string
     * @throws \Exception
     */
    public function alisa($output = null): string
    {
        $start = microtime(true);
        try {
            if (!isset(class_parents($this->processingCommand)['bot\processing\Command'])) {
                throw new \Exception('YandexBot::alisa(): Класс команд не унаследован от класса Command!');
            }
            if ($output) {
                $this->output = $output;
                if (!$this->output) {
                    throw new \Exception('YandexBot::alisa(): Инициализация output: Не удалось обработать команду!');
                }
            }
            $endSession = 'false';

            $this->initParam();
            if ($this->output['request']['type'] == 'ButtonPressed') {
                if ($this->commandTextFull) {
                    $key = $this->getKey();
                } else {
                    $key = -1;
                    $endSession = 'true';
                }
            } else {
                if ($this->messageId == 0) {
                    $key = 'hello';
                } else {
                    $key = $this->getKey();
                }
            }

            $this->command($key);

            $this->textMessage = str_replace('<br>', "\n", $this->textMessage);

            if ($key == 'by') {
                $endSession = 'true';
            }

            if ($key != 'ping') {
                if ($this->processingCommand->param === null) {
                    $this->processingCommand->getParams();
                }
            }

            $this->dopProcessing($key);
            if ($this->textMessage == '') {
                throw new \Exception('YandexBot::alisa(): Возвращается пустая строка!');
            }
            $end = microtime(true) - $start;
            if ($end >= 2.3) {
                throw new \Exception('YandexBot::alisa(): Не уложились во времени. Время ответа составило ' . $end . ' сек.');
            }
            return $this->sendMessage($endSession);
        } catch (\Exception $e) {
            $this->log($e);
            $this->processingCommand->image->isBigImage = false;
            $this->processingCommand->image->isItemsList = false;
            $this->urlMessage = '';
            $this->buttonMessage = '';

            $this->textMessage = 'Что-то пошло не по плану и мне вас не понять. 😞 Попробуйте переспросить меня об этом еще раз. ✌';
            $this->ttsMessage = $this->textMessage;
            $this->screen = false;
            return $this->sendMessage();
        }
    }

    /**
     * Логирование ошибок.
     *
     * @param \Exception $e
     */
    private function log(\Exception $e): void
    {
        $dir = __DIR__ . '/log';

        if (!is_dir($dir)) {
            mkdir($dir);
        }

        $fileError = fopen($dir . '/YandexBot.log', 'a');
        fwrite($fileError, "\n" . date('d-m-Y H:i:s') . ': ' . $e->getMessage());
        fwrite($fileError, "\nBotName: " . $this->name . "\nUserCommand: " . ($this->commandTextFull ?? 'undefined'));
        fwrite($fileError, "\nUserId: " . $this->userId);
        fwrite($fileError, "\n" . $e->getTraceAsString());
        fclose($fileError);
    }
}