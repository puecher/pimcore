<?php
namespace Pimcore\Image\Adapter;

use Pimcore\File;
use Pimcore\Image\Adapter;
use Pimcore\Logger;

class ImageMagick extends Adapter
{
    /**
     * @var null|string
     */
    protected $imagePath = null;

    /**
     * @var null|string
     */
    protected $outputPath = null;

    /**
     * Options used by the convert script
     *
     * @var array
     */
    protected $convertCommandOptions = [];

    /**
     * Options used by the composite script
     *
     * @var array
     */
    protected $compositeCommandOptions = [];

    /**
     * @var null|\Imagick
     */
    protected $resource = null;

    /**
     * Array with filters used with options
     *
     * @var array
     */
    protected $convertFilters = [];

    /**
     * @var string
     */
    protected $convertScriptPath = 'convert';

    /**
     * @var string
     */
    protected $compositeScriptPath = 'composite';


    /**
     * @param $imagePath
     * @param array $options
     * @return ImageMagick
     */
    public function load($imagePath, $options = [])
    {
        // support image URLs
        if (preg_match("@^https?://@", $imagePath)) {
            $tmpFilename = "imagick_auto_download_" . md5($imagePath) . "." . File::getFileExtension($imagePath);
            $tmpFilePath = PIMCORE_SYSTEM_TEMP_DIRECTORY . "/" . $tmpFilename;

            $this->tmpFiles[] = $tmpFilePath;

            File::put($tmpFilePath, \Pimcore\Tool::getHttpData($imagePath));
            $imagePath = $tmpFilePath;
        }

        if (!stream_is_local($imagePath)) {
            // imagick is only able to deal with local files
            // if your're using custom stream wrappers this wouldn't work, so we create a temp. local copy
            $tmpFilePath = PIMCORE_SYSTEM_TEMP_DIRECTORY . "/imagick-tmp-" . uniqid() . "." . File::getFileExtension($imagePath);
            copy($imagePath, $tmpFilePath);
            $imagePath = $tmpFilePath;
            $this->tmpFiles[] = $imagePath;
        }

        $this->imagePath = $imagePath;

        $this->initResource();

        $this->setModified(false);

        return $this;
    }

    /**
     * @return ImageMagick
     */
    protected function initResource()
    {
        if (null === $this->resource) {
            $this->resource = new \Imagick();
        }
        $this->resource->readImage($this->imagePath);
        $this->setWidth($this->resource->getImageWidth())
            ->setHeight($this->resource->getImageHeight());

        return $this;
    }

    /**
     * Save the modified image output in the specified path as the first argument.
     *
     * @param $path
     * @param null $format
     * @param null $quality
     * @return $this
     */
    public function save($path, $format = null, $quality = null)
    {
        $command = $this->getConvertCommand() . $path;
        recursiveCopy($this->imagePath, $path);
        exec($command);

        return $this;
    }

    /**
     * @return ImageMagick
     */
    protected function destroy()
    {
        foreach($this->tmpFiles as $tmpFile) {
            unlink($tmpFile);
        }

        return $this;
    }

    /**
     * Resize the image
     *
     * @param $width
     * @param $height
     * @return $this
     */
    public function resize($width, $height)
    {
        $this->addConvertOption('resize', "{$width}x{$height}");
        $this->setWidth($width);
        $this->setHeight($height);
        return $this;
    }

    /**
     * Adds frame which cause that the image gets exactly the entered dimensions by adding borders.
     *
     * @param $width
     * @param $height
     * @return ImageMagick
     */
    public function frame($width, $height)
    {
        $this->contain($width, $height);
        $frameWidth = $width - $this->getWidth() == 0 ? 0 : ($width - $this->getWidth()) / 2;
        $frameHeight = $height - $this->getHeight() == 0 ? 0 : ($height - $this->getHeight()) / 2;
        $this->addConvertOption('frame', "{$frameWidth}x{$frameHeight}")
            ->addConvertOption('alpha', 'set')
        ;

        return $this;
    }

    /**
     * @param int $tolerance
     * @return ImageMagick
     */
    public function trim($tolerance)
    {
        $this->addConvertOption('trim', $tolerance);

        return $this;
    }

    /**
     * Rotates the image with the given angle.
     * @param $angle
     * @return ImageMagick
     */
    public function rotate($angle)
    {
        $this->addConvertOption('rotate', $angle)
            ->addConvertOption('alpha', 'set')
        ;
        return $this;
    }

    /**
     * Cuts out a box of the image starting at the given X,Y coordinates and using the width and height.
     *
     * @param $x
     * @param $y
     * @param $width
     * @param $height
     * @return ImageMagick
     */
    public function crop($x, $y, $width, $height)
    {
        $this->addConvertOption('crop', "{$width}x{$height}+{$x}+{$y}");

        return $this;
    }

    /**
     * Set the background color of the image.
     *
     * @param $color
     * @return ImageMagick
     */
    public function setBackgroundColor($color)
    {

        $this->addConvertOption('background', "\"{$color}\"");

        return $this;
    }

    /**
     * Rounds the corners to the given width/height.
     *
     * @param $width
     * @param $height
     * @return ImageMagick
     */
    public function roundCorners($width, $height)
    {
        //creates the mask for rounded corners
        $mask = new ImageMagick();
        $mask->addConvertOption('size', "{$this->getWidth()}x{$this->getHeight()}")
            ->addConvertOption('draw', "'roundRectangle 0,0 {$this->getWidth()},{$this->getHeight()} {$width},{$height}'");
        $mask->addFilter('draw', 'xc:none');
        $tmpFilename = "imagick_mask_" . md5($this->imagePath) . '.png';
        $maskTargetPath = PIMCORE_SYSTEM_TEMP_DIRECTORY . "/" . $tmpFilename;
        exec((string) $mask . ' ' . $maskTargetPath);
        $this->tmpFiles[] = $maskTargetPath;

        $this
            ->addConvertOption('matte', $maskTargetPath)
            ->addConvertOption('compose', 'DstIn')
            ->addConvertOption('composite')
            ->addConvertOption('alpha', 'set')
        ;

        return $this;
    }

    /**
     * Set the image background
     *
     * @param $image
     * @param null $mode
     * @return ImageMagick
     */
    public function setBackgroundImage($image, $mode = null)
    {

        $image = ltrim($image, "/");
        $imagePath = PIMCORE_DOCUMENT_ROOT . "/" . $image;

        if (is_file($imagePath)) {
            //if a specified file as a background exists
            //creates the temp file for the background
            $newImage = $this->createTmpImage($imagePath, 'background');
            if ($mode == "cropTopLeft") {
                //crop the background image
                $newImage->crop(0, 0, $this->getWidth(), $this->getHeight());
            } else {
                // default behavior (fit)
                $newImage->resize($this->getWidth(), $this->getHeight());
            }
            $newImage->save($newImage->getOutputPath());

            //save current state of the thumbnail to the tmp file
            $this->setTmpPaths($this, 'gravity');
            $this->tmpFiles[] = $this->getOutputPath();
            $this->save($this->getOutputPath());

            //save the current state of the file (with a background)
            $this->compositeCommandOptions = [];
            $this->addCompositeOption('gravity', 'center ' . $this->getOutputPath() . ' ' . $newImage->getOutputPath() . ' ' . $this->getOutputPath());
            exec($this->getCompositeCommand());
            $this->imagePath = $this->getOutputPath();
        }


        return $this;
    }

    /**
     * @param string $image
     * @param int $x
     * @param int $y
     * @param int $alpha
     * @param string $composite
     * @param string $origin
     * @return ImageMagick
     */
    public function addOverlay($image, $x = 0, $y = 0, $alpha = 100, $composite = "COMPOSITE_DEFAULT", $origin = 'top-left')
    {
        $allowedComposeOptions = [
            'hardlight', 'exclusion'
        ];
        $composite = strtolower(substr(strrchr($composite, "_"),1));
        $composeVal = in_array($composite, $allowedComposeOptions) ? $composite : null;

        if (is_file($image)) {
            //if a specified file as a overlay exists
            $overlayImage = $this->createTmpImage($image, 'overlay');
            $overlayImage->addConvertOption('channel', 'a')->addConvertOption('evaluate', 'set ' . $alpha);

            //defines the position in order to the origin value
            switch ($origin) {
                case "top-right":
                    $x =  $overlayImage->getWidth() - $this->getWidth() - $x;
                    break;
                case "bottom-left":
                    $y =  $overlayImage->getHeight() - $this->getHeight() - $y;
                    break;
                case "bottom-right":
                    $x = $overlayImage->getWidth() - $this->getWidth()  - $x;
                    $y = $overlayImage->getHeight() - $this->getHeight() - $y;
                    break;
                case "center":
                    $x = round($overlayImage->getWidth() / 2) -round($this->getWidth() / 2) + $x;
                    $y = round($overlayImage->getHeight() / 2) - round($this->getHeight() / 2) + $y;
                    break;
            }
            //rop the overlay image
            $overlayImage->crop($x, $y, $this->getWidth(), $this->getHeight());
            $overlayImage->save($overlayImage->getOutputPath());


            //save current state of the thumbnail to the tmp file
            $tmpFilename = "imagemagick_compose_" . md5($this->imagePath) . '.png';
            $tmpFilepath = PIMCORE_SYSTEM_TEMP_DIRECTORY . "/" . $tmpFilename;
            $this->tmpFiles[] = $tmpFilepath;
            $this->save($tmpFilepath);

            //composes images together
            $this->compositeCommandOptions = [];
            $this
                ->addCompositeOption('compose', $composeVal . ' ' . $overlayImage->getOutputPath() . ' ' . $tmpFilepath . ' ' . $tmpFilepath);
            exec($this->getCompositeCommand());
            $this->imagePath = $tmpFilepath;
        }

        return $this;
    }

    public function addOverlayFit($image, $composite = "COMPOSITE_DEFAULT")
    {
    }

    /**
     * @param $image
     * @return ImageMagick
     */
    public function applyMask($image)
    {
        $this->addConvertOption('write-mask', $image);

        return $this;
    }

    /**
     * Cuts out a box of the image starting at the given X,Y coordinates and using percentage values of width and height.
     *
     * @param $x
     * @param $y
     * @param $width
     * @param $height
     * @return $this
     */
    public function cropPercent($x, $y, $width, $height)
    {
        $this->addConvertOption('crop-percent', "{$width}%x{$height}%+{$x}+{$y}");

        return $this;
    }

    /**
     * Converts the image into a linear-grayscale image.
     *
     * @return ImageMagick
     */
    public function grayscale($method = "Rec709Luminance")
    {
        $this->addConvertOption('grayscale', $method);

        return $this;
    }

    /**
     * Applies the sepia effect into the image.
     *
     * @return ImageMagick
     */
    public function sepia()
    {
        $this->addConvertOption('sepia-tone', "85%");
        return $this;
    }

    /**
     * Sharpen the image.
     *
     * @param int $radius
     * @param float $sigma
     * @param float $amount
     * @param float $threshold
     * @return ImageMagick
     */
    public function sharpen($radius = 0, $sigma = 1.0, $amount = 1.0, $threshold = 0.05)
    {
        $this->addConvertOption('sharpen', "'{$radius}x{$sigma}+$amount+$threshold'");
        return $this;
    }

    /**
     * Blur the image.
     *
     * @param int $radius
     * @param float $sigma
     * @return $this
     */
    public function gaussianBlur($radius = 0, $sigma = 1.0)
    {
        $this->addConvertOption('gaussian-blur', "{$radius}x{$sigma}");
        return $this;
    }

    /**
     * Brightness, saturation and hue setting of the image.
     *
     * @param int $brightness
     * @param int $saturation
     * @param int $hue
     * @return ImageMagick
     */
    public function brightnessSaturation($brightness = 100, $saturation = 100, $hue = 100)
    {
        $this->addConvertOption('modulate', "{$brightness},{$saturation},{$hue}");
        return $this;
    }

    /**
     * Creates vertical or horizontal mirror of the image.
     *
     * @param $mode
     * @return ImageMagick
     */
    public function mirror($mode)
    {
        if ($mode == "vertical") {
            $this->addConvertOption('flip');
        } elseif ($mode == "horizontal") {
            $this->addConvertOption('flop');
        }

        return $this;
    }

    /**
     * Add option to the command
     *
     * @param $name
     * @param null $value
     * @return ImageMagick
     */
    public function addConvertOption($name, $value = null)
    {
        $this->convertCommandOptions[$name] = $value;

        return $this;
    }

    /**
     * @param $name
     * @param null $value
     * @return ImageMagick
     */
    public function addCompositeOption($name, $value = null)
    {
        $this->compositeCommandOptions[$name] = $value;

        return $this;
    }

    /**
     * @param $optionName
     * @param $filterValue
     * @return $this
     */
    public function addFilter($optionName, $filterValue)
    {
        if(! isset($this->convertFilters[$optionName])) {
            $this->convertFilters[$optionName] = [];
        }

        $this->convertFilters[$optionName][] = $filterValue;

        return $this;
    }

    /**
     * @param $optionName
     * @return array
     */
    public function getConvertFilters($optionName)
    {
        return isset($this->convertFilters[$optionName]) ? $this->convertFilters[$optionName] : [];
    }

    /**
     *
     * @return string
     */
    public function getConvertCommand()
    {
        return "{$this->getConvertScriptPath()} {$this->getConvertOptionsAsString()}";
    }

    /**
     * @return string
     */
    public function getCompositeCommand()
    {
        return "{$this->getCompositeScriptPath()} {$this->getCompositeOptionsAsString()}";
    }

    /**
     * Returns options parameter for the convert command
     *
     * @return string
     */
    public function getConvertOptionsAsString()
    {
        $options = $this->imagePath . ' ';
        foreach($this->convertCommandOptions as $commandKey => $commandValue) {
            $options .= implode(' ', $this->getConvertFilters($commandKey)) . ' ';
            $options .= "-{$commandKey} {$commandValue} ";
        }

        return $options;
    }

    public function getCompositeOptionsAsString()
    {
        $options = '';
        foreach($this->compositeCommandOptions as $commandKey => $commandValue) {
            $options .= "-{$commandKey} {$commandValue} ";
        }

        return $options;
    }

    /**
     * Returns the convert cli script path.
     *
     * @return string
     */
    public function getConvertScriptPath()
    {
        return $this->convertScriptPath;
    }

    /**
     * Convert script path, as a default the adapter is just using 'convert'.
     *
     * @param $convertScriptPath
     * @return ImageMagick
     */
    public function setConvertScriptPath($convertScriptPath)
    {
        $this->convertScriptPath = $convertScriptPath;
        return $this;
    }

    /**
     * Returns the composite cli script path.
     *
     * @return string
     */
    public function getCompositeScriptPath()
    {
        return $this->compositeScriptPath;
    }

    /**
     * Composite script path, as a default the adapter is just using 'composite'.
     *
     * @param $convertScriptPath
     * @return ImageMagick
     */
    public function setCompositeScriptPath($compositeScriptPath)
    {
        $this->compositeScriptPath = $compositeScriptPath;

        return $this;
    }

    /**
     * @param $imagePath
     * @param $suffix
     * @return ImageMagick
     */
    protected function createTmpImage($imagePath, $suffix)
    {
        //if a specified file as a overlay exists
        $tmpImage = new ImageMagick();
        $tmpImage->load($imagePath);
        //creates the temp file for the background
        $this->setTmpPaths($tmpImage, $suffix);
        $this->tmpFiles[] = $tmpImage->getOutputPath();

        return $tmpImage;
    }

    protected function setTmpPaths(ImageMagick $image, $suffix)
    {
        $tmpFilename = "imagemagick_{$suffix}_" . md5($this->imagePath) . '.png';
        $tmpFilepath = PIMCORE_SYSTEM_TEMP_DIRECTORY . "/" . $tmpFilename;
        $image->setOutputPath($tmpFilepath);
        return $this;
    }

    /**
     * @return null|string
     */
    public function getOutputPath()
    {
        return $this->outputPath;
    }

    /**
     * @param $path
     * @return ImageMagick
     */
    public function setOutputPath($path)
    {
        $this->outputPath = $path;
        return $this;
    }
}