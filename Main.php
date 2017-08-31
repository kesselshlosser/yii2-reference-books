<?php
/**
 * Created by PhpStorm.
 * User: User
 * Date: 06.07.2016
 * Time: 9:41
 */

namespace common\components\klbase;

use Yii;
use yii\helpers\ArrayHelper;
use yii\base\Component;
use yii\base\UnknownPropertyException;

use common\components\klbase\models\Bases;
use common\components\klbase\models\KlbaseData;

class Main extends Component
{

    const CACHE_CONFIG_NAME = 'klbaseMainDirectory';
    const CACHE_BASE_PREFIX = 'klbaseItem';
    const CACHE_DURATION = 3600000;
    const PREFIX_BASE = 'kl';

    const DB_PAGE_SIZE = 1000;

    private $baseDirectory = [];

    private $basesConteiner = [];

    /**
     * @var array Список УРЛ методов АПИ для виджетов
     *      'title' - Список Названий элементов
     */
    public  $apiUrl = [
        'title' =>  '/sys/klbase/api/title-list',
        'value' =>  '/sys/klbase/api/value-list',
    ];

    /**
     * @var int  Максимальное количество дополнительный полей справочника
     */
    public $extFieldLimit = 6;

    public function init()
    {
        parent::init(); // TODO: Change the autogenerated stub
        $this->readDirectoryCache();
    }


    /**
     * @param string $name
     * @return mixed
     * @throws UnknownPropertyException
     *
     * Получение Обекта-справочника
     */
    public function __get($name)
    {
        $inBaseName = self::baseNameInflectorExtToIn($name);

        if( isset($this->baseDirectory[$inBaseName]) ){
            if( ! isset($this->basesConteiner[$name]) ){
                $this->createBaseObj($name, $this->readBaseCache($name));
            }

            return $this->basesConteiner[$name];
        }



        throw new UnknownPropertyException("KlBase: [$name] not Found");
    }



    /*
     * =================================================================================================================
     * Секция  Каталог свойст справочников
     */

    /**
     * @param null $id
     * @return array|null
     */
    public function getDirectory($id = null)
    {
        if($id == null) return $this->baseDirectory;

        if( isset($this->baseDirectory[$id]) ){
            return $this->baseDirectory[$id];
        }else{
            return null;
        }
    }

    /**
     * @param bool $return
     * @return array|\yii\db\ActiveRecord[]
     *
     * Создание кеша справочника доступных баз
     */
    public function buildDirectoryCache($return = true)
    {
        $bases = Bases::find()->all();

        if($bases == null){
            $bases = [];
        }else{
            foreach($bases as $key=>$base){
                $bases[$key] = $base->attributes;
            }
            $bases = ArrayHelper::index($bases, 'name');
        }

        Yii::$app->cache->set(self::CACHE_CONFIG_NAME, $bases, self::CACHE_DURATION);
        if($return){
            return $bases;
        }else{
            $this->readDirectoryCache();
        }
    }

    /**
     *  Чтение кеша с справочником доступных баз
     */
    private function readDirectoryCache()
    {
        $data = Yii::$app->cache->get(self::CACHE_CONFIG_NAME);
        if($data == false){
            $this->baseDirectory = $this->buildDirectoryCache();
        }else{
            $this->baseDirectory = $data;
        }
    }


    /*
     * =================================================================================================================
     * Секция  Контейнер справочников
     */

    /**
     * @param $inBaseName
     * @param bool $return
     * @return array Создание кеша конкретного справочника
     *
     * Создание кеша конкретного справочника
     * @internal param $baseName
     */
    public function buildBaseCache($inBaseName, $return = true)
    {
        $baseData = [];
        $count = KlbaseData::findBase($inBaseName)->count();
        $page = ceil( $count / self::DB_PAGE_SIZE );

        for($i = 0; $i < $page; $i++){
            $data = KlbaseData::findBase($inBaseName)->limit(self::DB_PAGE_SIZE)->offset( self::DB_PAGE_SIZE * $i )->all();

            if($data != null){
                foreach($data as $key=>$base){
                    $baseData[$base->data_id] = array_merge(
                        [
                            'value'     => $base->value,
                            'title'     => $base->title,
                            'relation'  => $base->relation
                        ], $base->fields ? $base->fields : []);
                }

            }
        }

        Yii::$app->cache->set(self::baseNameInflectorInToExt($inBaseName), $baseData, self::CACHE_DURATION);
        if($return) return $baseData;
    }


    /**
     * @param $inBaseName
     *
     * Перстройка доп. полей справочника после изменений структуры
     */
    public function rebuildingBaseData($inBaseName)
    {

        $count = KlbaseData::findBase($inBaseName)->count();
        $page = ceil( $count / self::DB_PAGE_SIZE );

        for($i = 0; $i < $page; $i++){
            $baseData = KlbaseData::findBase($inBaseName)->limit(self::DB_PAGE_SIZE)->offset( self::DB_PAGE_SIZE * $i )->all();

            foreach($baseData as $model){
                $extFields = [];
                if( $this->baseDirectory[$inBaseName]['relation'] != ''){
                    if($model->relation == null) $model->relation = 110;
                }else{
                    $model->relation = null;
                }

                // Читаем динамические поля
                foreach ($this->baseDirectory[$inBaseName]['fields'] as $field) {
                    $propName = 'ext_' . $field['name'];
                    if( isset($model->fields[$propName]) ){
                        $extFields[$propName] = $model->fields[$propName];
                    }else{
                        $extFields[$propName] = '';
                    }
                }
                // Сериализируем
                $model->fields = serialize($extFields);

                // Сохраняем без валидации
                $model->save(false);
            }

        }

    }


    /**
     * @param $inBaseName
     * @return array|mixed
     *
     * Чтение кеша конкретного справочника
     */
    private function readBaseCache($inBaseName)
    {
        $data = Yii::$app->cache->get(self::baseNameInflectorInToExt($inBaseName));
        if($data == false){
            $data = $this->buildBaseCache($inBaseName);
        }

        return $data;
    }


    /**
     * @param $baseName
     * @param $baseData
     *
     * Добавление в контейнер
     */
    private function createBaseObj($inBaseName, $baseData)
    {
        // Получаем конфигуратор справочника
        $struct = $this->getDirectory($inBaseName);

        // Получаем зависимый справочник, если есть
        if($struct['relation'] != ''){
            $relName = self::baseNameInflectorInToExt($inBaseName);
            $relation = $this->$relName;
        }else{
            $relation = null;
        }

        // Добавляем в контейнер обект справочника
        $this->basesConteiner[ $inBaseName ] = new BaseItem($baseData, $struct, $relation);
    }






    /*
     * =================================================================================================================
     * Секция  Хелперы
     */

    /**
     * @param $baseName
     * @return string
     *
     * Преобразовывает внешнее Имя справочника во внутренее
     */
    public static function baseNameInflectorExtToIn($baseName)
    {
        if( strpos($baseName, self::PREFIX_BASE) === 0){
            return substr($baseName, (strlen(self::PREFIX_BASE)));
        }else{
            return $baseName;
        }
    }

    /**
     * @param $baseName
     * @return string
     *
     * Преобразовывает внутренее Имя справочника во внешнее
     */
    public static function baseNameInflectorInToExt($baseName)
    {
        if( strpos($baseName, self::PREFIX_BASE) === 0){
            return $baseName;
        }else{
            return self::PREFIX_BASE . $baseName;
        }
    }






}