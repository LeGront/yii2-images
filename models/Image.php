<?php

namespace legront\images\models;

use abeautifulsite\SimpleImage;
use legront\images\ModuleTrait;
use Yii;
use yii\base\Exception;
use yii\db\ActiveRecord;
use yii\helpers\BaseFileHelper;
use yii\helpers\Inflector;
use yii\helpers\Url;

/**
 * This is the model class for table "image".
 *
 * @property integer $id
 * @property string $filePath
 * @property integer $itemId
 * @property integer $isMain
 * @property string $modelName
 * @property string $urlAlias
 * @property string $name
 *
 * @property bool $main
 * @property string $subDur
 * @property string $pathToOrigin
 * @property array $sizes
 * @property mixed $extension
 */
class Image extends ActiveRecord
{
    use ModuleTrait;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%image}}';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['filePath', 'itemId', 'modelName', 'urlAlias'], 'required'],
            [['itemId', 'isMain'], 'integer'],
            [['name'], 'string', 'max' => 80],
            [['filePath', 'urlAlias'], 'string', 'max' => 400],
            [['modelName'], 'string', 'max' => 150],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id'        => 'ID',
            'filePath'  => 'File Path',
            'itemId'    => 'Item ID',
            'isMain'    => 'Is Main',
            'modelName' => 'Model Name',
            'urlAlias'  => 'Url Alias',
        ];
    }

    /**
     * @return bool
     */
    public function clearCache()
    {
        $subDir = $this->getSubDur();

        $dirToRemove = $this->getModule()->getCachePath() . DIRECTORY_SEPARATOR . $subDir;

        if (preg_match('/' . preg_quote($this->modelName, '/') . '/', $dirToRemove)) {
            BaseFileHelper::removeDirectory($dirToRemove);
        }

        return true;
    }

    /**
     * @return string
     */
    protected function getSubDur()
    {
        return Inflector::pluralize($this->modelName) . '/' . $this->modelName . $this->itemId;
    }

    /**
     * @param int|bool $size
     *
     * @return string
     */
    public function getUrl($size = false)
    {
        $urlSize = ($size) ? '_' . $size : '';
        $url     = Url::toRoute([
            '/' . $this->getModule()->id . '/images/image-by-item-and-alias',
            'item'       => $this->modelName . $this->itemId,
            'dirtyAlias' => $this->urlAlias . $urlSize . '.' . $this->getExtension(),
        ]);

        return $url;
    }

    /**
     * @return mixed
     */
    public function getExtension()
    {
        $ext = pathinfo($this->getPathToOrigin(), PATHINFO_EXTENSION);

        return $ext;
    }

    /**
     * @return string
     */
    public function getPathToOrigin()
    {
        $base = $this->getModule()->getStorePath();

        $filePath = $base . DIRECTORY_SEPARATOR . $this->filePath;

        return $filePath;
    }

    /**
     * @param int|bool $size
     *
     * @return string
     * @throws \Exception
     */
    public function getLink($size = false)
    {
        $urlSize     = ($size) ? '_' . $size : '';
        $base        = $this->getModule()->getCachePath();
        $imageDomain = $this->getModule()->getImageDomain();
        $sub         = $this->getSubDur();

        $origin = $this->getPathToOrigin();

        $filePath = $base . DIRECTORY_SEPARATOR . $sub . DIRECTORY_SEPARATOR . $this->urlAlias . $urlSize . '.' . pathinfo($origin, PATHINFO_EXTENSION);
        if (!file_exists($filePath)) {
            $this->createVersion($origin, $size);

            if (!file_exists($filePath)) {
                throw new \Exception('Problem with image creating.');
            }
        }

        return $imageDomain . $sub . DIRECTORY_SEPARATOR . $this->urlAlias . $urlSize . '.' . pathinfo($origin, PATHINFO_EXTENSION);
    }

    /**
     * @param string $imagePath
     * @param bool $sizeString
     *
     * @return SimpleImage|\Imagick
     * @throws Exception
     * @throws \Exception
     */
    public function createVersion($imagePath, $sizeString = false)
    {
        if (strlen($this->urlAlias) < 1) {
            throw new \Exception('Image without urlAlias!');
        }

        $cachePath     = $this->getModule()->getCachePath();
        $subDirPath    = $this->getSubDur();
        $fileExtension = pathinfo($this->filePath, PATHINFO_EXTENSION);

        if ($sizeString) {
            $sizePart = '_' . $sizeString;
        } else {
            $sizePart = '';
        }

        $pathToSave = $cachePath . '/' . $subDirPath . '/' . $this->urlAlias . $sizePart . '.' . $fileExtension;

        BaseFileHelper::createDirectory(dirname($pathToSave), 0777, true);

        if ($sizeString) {
            $size = $this->getModule()->parseSize($sizeString);
        } else {
            $size = false;
        }

        if ($this->getModule()->graphicsLibrary == 'Imagick') {
            $image = new \Imagick($imagePath);
            $image->setImageCompressionQuality(100);

            if ($size) {
                if ($size['height'] && $size['width']) {
                    $image->cropThumbnailImage($size['width'], $size['height']);
                } elseif ($size['height']) {
                    $image->thumbnailImage(0, $size['height']);
                } elseif ($size['width']) {
                    $image->thumbnailImage($size['width'], 0);
                } else {
                    throw new \Exception('Something wrong with this->module->parseSize($sizeString)');
                }
            }

            $image->writeImage($pathToSave);
        } else {
            $image = new SimpleImage($imagePath);

            if ($size) {
                if ($size['height'] && $size['width']) {
                    $image->thumbnail($size['width'], $size['height']);
                } elseif ($size['height']) {
                    $image->fit_to_height($size['height']);
                } elseif ($size['width']) {
                    $image->fit_to_width($size['width']);
                } else {
                    throw new \Exception('Something wrong with this->module->parseSize($sizeString)');
                }
            }

            //WaterMark
            if ($this->getModule()->waterMark) {
                if (!file_exists(Yii::getAlias($this->getModule()->waterMark))) {
                    throw new Exception('WaterMark not detected!');
                }

                $wmMaxWidth  = intval($image->get_width() * 0.4);
                $wmMaxHeight = intval($image->get_height() * 0.4);

                $waterMarkPath = Yii::getAlias($this->getModule()->waterMark);

                $waterMark = new SimpleImage($waterMarkPath);

                if ($waterMark->get_height() > $wmMaxHeight
                    or
                    $waterMark->get_width() > $wmMaxWidth
                ) {
                    $waterMarkPath = $this->getModule()->getCachePath() . DIRECTORY_SEPARATOR .
                        pathinfo($this->getModule()->waterMark)['filename'] .
                        $wmMaxWidth . 'x' . $wmMaxHeight . '.' .
                        pathinfo($this->getModule()->waterMark)['extension'];

                    //throw new Exception($waterMarkPath);
                    if (!file_exists($waterMarkPath)) {
                        $waterMark->fit_to_width($wmMaxWidth);
                        $waterMark->save($waterMarkPath, 100);
                        if (!file_exists($waterMarkPath)) {
                            throw new Exception('Cant save watermark to ' . $waterMarkPath . '!!!');
                        }
                    }
                }

                $image->overlay($waterMarkPath, 'bottom right', .5, -10, -10);
            }

            $image->save($pathToSave, 100);
        }

        return $image;
    }

    /**
     * @param int|bool $size
     *
     * @return string
     */
    public function getContent($size = false)
    {
        return file_get_contents($this->getPath($size));
    }

    /**
     * @param int|bool $size
     *
     * @return string
     * @throws \Exception
     */
    public function getPath($size = false)
    {
        $urlSize = ($size) ? '_' . $size : '';
        $base    = $this->getModule()->getCachePath();
        $sub     = $this->getSubDur();

        $origin = $this->getPathToOrigin();

        $filePath = $base . DIRECTORY_SEPARATOR .
            $sub . DIRECTORY_SEPARATOR . $this->urlAlias . $urlSize . '.' . pathinfo($origin, PATHINFO_EXTENSION);
        if (!file_exists($filePath)) {
            $this->createVersion($origin, $size);

            if (!file_exists($filePath)) {
                throw new \Exception('Problem with image creating.');
            }
        }

        return $filePath;
    }

    /**
     * @param string $sizeString
     *
     * @return array
     * @throws \Exception
     */
    public function getSizesWhen($sizeString)
    {
        $size = $this->getModule()->parseSize($sizeString);
        if (!$size) {
            throw new \Exception('Bad size..');
        }

        $sizes = $this->getSizes();

        $imageWidth  = $sizes['width'];
        $imageHeight = $sizes['height'];
        $newSizes    = [];
        if (!$size['width']) {
            $newWidth           = $imageWidth * ($size['height'] / $imageHeight);
            $newSizes['width']  = intval($newWidth);
            $newSizes['height'] = $size['height'];
        } elseif (!$size['height']) {
            $newHeight          = intval($imageHeight * ($size['width'] / $imageWidth));
            $newSizes['width']  = $size['width'];
            $newSizes['height'] = $newHeight;
        }

        return $newSizes;
    }

    /**
     * @return array
     */
    public function getSizes()
    {
        $sizes = false;
        if ($this->getModule()->graphicsLibrary == 'Imagick') {
            $image = new \Imagick($this->getPathToOrigin());
            $sizes = $image->getImageGeometry();
        } else {
            $image           = new SimpleImage($this->getPathToOrigin());
            $sizes['width']  = $image->get_width();
            $sizes['height'] = $image->get_height();
        }

        return $sizes;
    }

    /**
     * @param bool $isMain
     */
    public function setMain($isMain = true)
    {
        if ($isMain) {
            $this->isMain = 1;
        } else {
            $this->isMain = 0;
        }
    }
}
