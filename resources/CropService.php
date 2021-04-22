<?php

namespace ImageFocus;

/**
 * The class responsible for cropping the attachments
 *
 * Class CropService
 * @package ImageFocus
 */
class CropService
{
    private $attachment = [];
    private $imageSizes = [];
    private $focusPoint = ['x' => 50, 'y' => 50];

    /**
     * Crop the image on base of the focus point
     *
     * @param $attachmentId
     * @param $focusPoint
     * @return bool
     */
    public function crop($attachmentId, $focusPoint)
    {
        // Set all the cropping data
        $this->setCropData($attachmentId, $focusPoint);
        $this->cropAttachment();
    }

    /**
     * Set all crop data
     *
     * @param $attachmentId
     * @param $focusPoint
     */
    private function setCropData($attachmentId, $focusPoint)
    {
        $this->getImageSizes();
        $this->getAttachment($attachmentId);
        $this->setFocusPoint($focusPoint);
        $this->saveFocusPointToDB();
    }

    /**
     * Get all the image sizes excluding the ones that don't need cropping
     *
     * @return $this
     */
    public function getImageSizes()
    {
        // Get all the default WordPress image Sizes
        foreach (
            (array) get_intermediate_image_sizes() as $imageSize
        ) {
            if (in_array($imageSize, ['thumbnail', 'medium', 'medium_large', 'large'], true) && get_option("{$imageSize}_crop")) {
                $this->imageSizes[$imageSize] = [
                    'width'  => (int)get_option("{$imageSize}_size_w"),
                    'height' => (int)get_option("{$imageSize}_size_h"),
                    'crop'   => (bool)get_option("{$imageSize}_crop"),
                    'ratio'  => (float)get_option("{$imageSize}_size_w") / (int)get_option("{$imageSize}_size_h")
                ];
            }
        }

        // Get all the custom set image Sizes
        foreach ((array)wp_get_additional_image_sizes() as $key => $imageSize) {
            if ($imageSize['crop']) {
                $this->imageSizes[$key] = $imageSize;
                $this->imageSizes[$key]['ratio'] = (float)$imageSize['width'] / $imageSize['height'];
            }
        }

        return $this;
    }

    /**
     *  Return the src array of the attachment image containing url, width & height
     *
     * @param $attachmentId
     * @return $this
     */
    private function getAttachment($attachmentId)
    {
        $attachment = wp_get_attachment_image_src($attachmentId, 'full');

        $this->attachment = [
            'id'     => $attachmentId,
            'src'    => (string)$attachment[0],
            'width'  => (int)$attachment[1],
            'height' => (int)$attachment[2],
            'ratio'  => (float)$attachment[1] / $attachment[2]
        ];

        return $this;
    }

    /**
     * Set the focuspoint for the crop
     *
     * @param $focusPoint
     * @return $this
     */
    private function setFocusPoint($focusPoint)
    {
        if ($focusPoint) {
            $this->focusPoint = $focusPoint;
        }

        return $this;
    }

    /**
     * Put the focuspoint in the post meta of the attachment post
     */
    private function saveFocusPointToDB()
    {
        update_post_meta($this->attachment['id'], 'focus_point', $this->focusPoint);
    }

    /**
     * Crop the actual attachment
     */
    private function cropAttachment()
    {
        // Loop trough all the image sizes connected to this attachment
        foreach ($this->imageSizes as $name => $imageSize) {


            // Stop this iteration if the attachment is too small to be cropped for this image size
            if ($imageSize['width'] > $this->attachment['width'] || $imageSize['height'] > $this->attachment['height']) {
                continue;
            }

            try {
                // Get the file path of the attachment and the delete the old image
                $imageFilePath = $this->getImageFilePath($name);

            } catch (\Exception $exception) {
                continue;
            }

            // Remove existing image
            @unlink($imageFilePath);

            // Now execute the actual image crop
            $this->cropImage($imageSize, $imageFilePath);
        }
    }

    /**
     * Get the file destination based on the attachment in the argument
     *
     * @param $imageSize
     * @return mixed
     */
    private function getImageFilePath($imageSize)
    {
        $filePath = image_get_intermediate_size($this->attachment['id'], $imageSize)["path"] ?? null;

        if ($filePath === null) {
            throw new \Exception("No path found for $imageSize in attachment: {$this->attachment['id']}");
        }

        // Get the path to the WordPress upload directory
        $uploadDir = wp_upload_dir()['basedir'] . '/';

        return $uploadDir . $filePath;
    }

    /**
     * Calculate the all of the positions necessary to crop the image and crop it.
     *
     * @param $imageSize
     * @param $imageFilePath
     * @return $this
     */
    private function cropImage($imageSize, $imageFilePath)
    {
        // Gather all dimension
        $dimensions = ['x' => [], 'y' => []];
        $directions = ['x' => 'width', 'y' => 'height'];

        // Define the correction the image needs to keep the same ratio after the cropping has taken place
        $cropCorrection = [
            'x' => $imageSize['ratio'] / $this->attachment['ratio'],
            'y' => $this->attachment['ratio'] / $imageSize['ratio']
        ];

        // Check all the cropping values
        foreach ($dimensions as $axis => $dimension) {

            // Get the center position
            $dimensions[$axis]['center'] = $this->focusPoint[$axis] / 100 * $this->attachment[$directions[$axis]];
            // Get the starting position and let's correct the crop ratio
            $dimensions[$axis]['start'] = $dimensions[$axis]['center'] - $this->attachment[$directions[$axis]] * $cropCorrection[$axis] / 2;
            // Get the ending position and let's correct the crop ratio
            $dimensions[$axis]['end'] = $dimensions[$axis]['center'] + $this->attachment[$directions[$axis]] * $cropCorrection[$axis] / 2;

            // Is the start position lower than 0? That's not possible so let's correct it
            if ($dimensions[$axis]['start'] < 0) {
                // Adjust the ending, but don't make it higher than the image itself
                $dimensions[$axis]['end'] = min($dimensions[$axis]['end'] - $dimensions[$axis]['start'],
                    $this->attachment[$directions[$axis]]);
                // Adjust the start, but don't make it lower than 0
                $dimensions[$axis]['start'] = max($dimensions[$axis]['start'] - $dimensions[$axis]['start'], 0);
            }

            // Is the start position higher than the total image size? That's not possible so let's correct it
            if ($dimensions[$axis]['end'] > $this->attachment[$directions[$axis]]) {
                // Adjust the start, but don't make it lower than 0
                $dimensions[$axis]['start'] = max($dimensions[$axis]['start'] + $this->attachment[$directions[$axis]] - $dimensions[$axis]['end'],
                    0);
                // Adjust the ending, but don't make it higher than the image itself
                $dimensions[$axis]['end'] = min($dimensions[$axis]['end'] + $this->attachment[$directions[$axis]] - $dimensions[$axis]['end'],
                    $this->attachment[$directions[$axis]]);
            }
        }

        // Excecute the WordPress image crop function
        $newPath = wp_crop_image($this->attachment['id'],
            $dimensions['x']['start'],
            $dimensions['y']['start'],
            $dimensions['x']['end'] - $dimensions['x']['start'],
            $dimensions['y']['end'] - $dimensions['y']['start'],
            $imageSize['width'],
            $imageSize['height'],
            false,
            $imageFilePath
        );

        // Since WP 5.4 wp_crop_image always generates a -1.png unique path for image subsizes
        // @see https://github.com/WordPress/WordPress/commit/15a566edef8afe6dc1f23a841bd557a8cecf35d1
        // We copy the newly created file over to the old path and then remove the new one.
        copy($newPath, $imageFilePath);
        unlink($newPath);

        return $this;
    }
}