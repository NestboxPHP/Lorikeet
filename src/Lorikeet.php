<?php

declare(strict_types=1);

namespace NestboxPHP\Lorikeet;

use NestboxPHP\Lorikeet\Exception\LorikeetException;
use NestboxPHP\Nestbox\Nestbox;

class Lorikeet extends Nestbox
{
    final public const PACKAGE_NAME = 'lorikeet';
    public const LORIKEET_IMAGE_TABLE = 'lorikeet_images';
    public const LORIKEET_TAG_TABLE = 'lorikeet_tags';
    public const LORIKEET_IMAGE_DIRECTORY = Nestbox::NESTBOX_DIRECTORY . "/lorikeet";

    // settings variables
    public int $lorikeetMaxWidth = 0;
    public int $lorikeetMaxHeight = 0;
    public int $lorikeetThumbnailMaxWidth = 250;
    public int $lorikeetThumbnailMaxHeight = 250;
    public int $lorikeetMaxFilesizeMb = 2;
    public string $lorikeetConvertToFiletype = "webp";
    public string $lorikeetVirusTotalApiKey = "";

    public function create_class_table_lorikeet_images(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS `" . static::LORIKEET_IMAGE_TABLE . "` (
                    `image_id` VARCHAR( 64 ) NOT NULL ,
                    `image_title` VARCHAR( 128 ) NULL ,
                    `image_description` VARCHAR( 1024 ) NULL ,
                    `uploader` VARCHAR( 400 ) NOT NULL ,
                    `uploaded` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ,
                    `edited` TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ,
                    PRIMARY KEY ( `image_id` )
                ) ENGINE = InnoDB DEFAULT CHARSET=UTF8MB4 COLLATE=utf8mb4_general_ci;";

        return $this->query_execute($sql);
    }

    public function create_class_table_lorikeet_tags(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS `" . static::LORIKEET_TAG_TABLE . "` (
                    `tag_id` INT AUTO_INCREMENT NOT NULL ,
                    `image_id` VARCHAR( 64 ) NOT NULL ,
                    `tag_name` VARCHAR( 64 ) NOT NULL , 
                    UNIQUE KEY unique_combination ( `image_id`, `tag_name` ) ,
                    PRIMARY KEY ( `tag_id` )
                ) ENGINE = InnoDB DEFAULT CHARSET=UTF8MB4 COLLATE=utf8mb4_general_ci;";

        return $this->query_execute($sql);
    }

    /**
     * Gets a clean string of the image save directory.
     *
     * @return string
     */
    public function get_save_directory(): string
    {
        return $this->generate_document_root_relative_path(path: static::LORIKEET_IMAGE_DIRECTORY);
    }


    public function change_save_directory(string $newDirectory): bool
    {
        // create new directory
        // move files from old directory to new directory
        // delete old files and directory
        return true;
    }

    /**
     * Gets a list of all images stored in the database.
     *
     * @return array
     */
    public function list_images(): array
    {
        $sql = "SELECT *, GROUP_CONCAT(`" . static::LORIKEET_TAG_TABLE . "`.`tag_name`) as `tags`
                FROM `" . static::LORIKEET_IMAGE_TABLE . "`
                LEFT JOIN `" . static::LORIKEET_TAG_TABLE . "` USING ( `image_id` )
                GROUP BY `image_id`;
                ORDER BY `image_title` ASC;";

        if (!$this->query_execute($sql)) return [];
        return $this->fetch_all_results();
    }

    /**
     * Accepts the array object from $_FILES then processes and saves the image.
     *
     * @param array $file
     * @return string|bool
     * @throws LorikeetException
     */
    public function process_image_upload(array $file): string|bool
    {
        // check for errors
        $errorMessage = match ($file["error"]) {
            UPLOAD_ERR_OK => "",
            UPLOAD_ERR_INI_SIZE => "The uploaded file exceeds the server max file size.",
            UPLOAD_ERR_FORM_SIZE => "The uploaded file exceeds the HTML form max file size.",
            UPLOAD_ERR_PARTIAL => "The uploaded file was only partially uploaded.",
            UPLOAD_ERR_NO_FILE => "No file was uploaded.",
            UPLOAD_ERR_NO_TMP_DIR => "Missing a temporary folder.",
            UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk.",
            UPLOAD_ERR_EXTENSION => "A PHP extension stopped the file upload.",
            default => "Unknown error on file upload."
        };
        if ($errorMessage)
            throw new LorikeetException($errorMessage);

        // verify upload
        $tempFile = $file["tmp_name"];
        if (!$tempFile)
            throw new LorikeetException("No file upload detected.");

        if (!file_exists($tempFile))
            throw new LorikeetException("Temporary upload file not found.");

        // verify file size is not zero
        $fileSize = filesize($tempFile);
        if (0 == $fileSize)
            throw new LorikeetException("Zero-size file uploaded.");

        // verify the file size is less than the max
        if ($this->lorikeetMaxFilesizeMb * 1024 * 1024 < $fileSize)
            throw new LorikeetException("File is larger than image upload limit.");

        // get the hash of a file
        $fileHash = hash_file("sha256", $tempFile);
        if ($this->get_image($fileHash))
            throw new LorikeetException("Duplicate image already exists.");

        // get the MIME type
        $mimeTypes = [
            "image/gif",
            "image/jpeg",
            "image/png",
            "image/bmp",
            "image/webp",
        ];
        $mimeType = mime_content_type($tempFile);
        if (!in_array($mimeType, $mimeTypes))
            throw new LorikeetException("Invalid MIME type: {$mimeType}");

        // get scaling sizes
        $imageSize = getimagesize($tempFile);
        $sizeRatio = $this->calculate_resize_ratio($tempFile);
        $outputWidth = intval($sizeRatio * $imageSize[0]);
        $outputHeight = intval($sizeRatio * $imageSize[1]);

        $thumbRatio = $this->calculate_thumbnail_ratio($tempFile);
        $thumbnailWidth = intval($thumbRatio * $imageSize[0]);
        $thumbnailHeight = intval($thumbRatio * $imageSize[1]);

        // create image resource from uploaded image
        $uploadedImage = match ($mimeType) {
            "image/gif" => imagecreatefromgif($tempFile),
            "image/jpeg" => imagecreatefromjpeg($tempFile),
            "image/png" => imagecreatefrompng($tempFile),
            "image/bmp" => imagecreatefrombmp($tempFile),
            "image/webp" => imagecreatefromwebp($tempFile),
            default => false
        };
        if (!$uploadedImage)
            throw new LorikeetException("Failure to read uploaded image file.");

        // create image resources to save to
        $outputImage = imagecreatetruecolor($outputWidth, $outputHeight);
        if (!$outputImage)
            throw new LorikeetException("Couldn't create true color image.");
        imagecolortransparent($outputImage, imagecolorallocate($outputImage, 0, 0, 0));

        $thumbnailImage = imagecreatetruecolor($thumbnailWidth, $thumbnailHeight);
        if (!$thumbnailImage)
            throw new LorikeetException("Couldn't create true color image.");
        imagecolortransparent($thumbnailImage, imagecolorallocate($outputImage, 0, 0, 0));

        // copy image data from uploaded file to new resources
        imagecopyresized($outputImage, $uploadedImage, 0, 0, 0, 0, $outputWidth, $outputHeight, $imageSize[0], $imageSize[1]);
        imagecopyresized($thumbnailImage, $uploadedImage, 0, 0, 0, 0, $thumbnailWidth, $thumbnailHeight, $imageSize[0], $imageSize[1]);

        // create save paths
        $saveDirectory = $this->get_save_directory();
        $this->create_document_root_relative_directory($saveDirectory, 0666);
        $outputImageFullPath = $saveDirectory . DIRECTORY_SEPARATOR . "$fileHash.webp";
        $thumbnailImageFullPath = $saveDirectory . DIRECTORY_SEPARATOR . "{$fileHash}_thumb.webp";

        if (!imagewebp($outputImage, $outputImageFullPath))
            throw new LorikeetException("Failed to save output image.");
        if (!imagewebp($thumbnailImage, $thumbnailImageFullPath))
            throw new LorikeetException("Faield to save thumbnail image.");

        return $fileHash;
    }

    /**
     * Calculates the scaling ratio based on the max width and height lorikeet settings.
     *
     * @param $originalImage
     * @return float
     */
    public function calculate_resize_ratio($originalImage): float
    {
        $imageSize = getimagesize($originalImage);
        $widthScale = (0 < $this->lorikeetMaxWidth and $this->lorikeetMaxWidth < $imageSize[0])
            ? $this->lorikeetMaxWidth / $imageSize[0] : 1;
        $heightScale = (0 < $this->lorikeetMaxHeight and $this->lorikeetMaxHeight < $imageSize[1])
            ? $this->lorikeetMaxHeight / $imageSize[1] : 1;
        return min($widthScale, $heightScale);
    }

    /**
     * Calculates the scaling ratio based on the max thumbnail width and height lorikeet settings.
     *
     * @param $originalImage
     * @return float
     */
    public function calculate_thumbnail_ratio($originalImage): float
    {
        $imageSize = getimagesize($originalImage);
        $widthScale = (0 < $this->lorikeetThumbnailMaxWidth and $this->lorikeetThumbnailMaxWidth < $imageSize[0])
            ? $this->lorikeetThumbnailMaxWidth / $imageSize[0] : 1;
        $heightScale = (0 < $this->lorikeetThumbnailMaxWidth and $this->lorikeetThumbnailMaxWidth < $imageSize[1])
            ? $this->lorikeetThumbnailMaxWidth / $imageSize[1] : 1;
        return min($widthScale, $heightScale);
    }

    // image database entries
    public function add_image(array $file, string $title, string $caption, string|array $tags = null): bool
    {
        $fileHash = $this->process_image_upload($file);

        $row = [
            "image_id" => $fileHash,
            "image_title" => $title,
            "image_caption" => $caption,
        ];

        if (false === $this->insert(static::LORIKEET_IMAGE_TABLE, $row)) return false;

        $tags = $this->process_tags($fileHash, $tags);
        var_dump($tags);
        $addedTags = $this->insert(static::LORIKEET_TAG_TABLE, $tags);
        var_dump("addedTags: $addedTags");

        return true;
    }

    public function edit_image(string $title, string $caption, string|array $tags = null): bool
    {
        return true;
    }

    public function delete_image(): bool
    {
        return true;
    }

    public function select_image(string $id): array
    {
        return $this->select(table: static::LORIKEET_IMAGE_TABLE, where: ["image_id" => $id])[0] ?? [];
    }

    protected function clean_tags(string|array $tags): array
    {
        if (!is_array($tags)) $tags = [$tags];
        $cleanedTags = [];

        foreach ($tags as $data) {
            $tagArray = explode(",", $data);
            foreach ($tagArray as $tag) {
                $cleanTag = trim($tag);
                if (!$cleanTag) continue;
                $cleanedTags[] = $cleanTag;
            }
        }

        return $cleanedTags;
    }

    protected function process_tags(string $imageId, array|string $tags = null): array
    {
        if (!$tags) return [];
        $tags = $this->clean_tags($tags);
        $processedTags = [];

        foreach ($tags as $tag) {
            $processedTags[] = [
                "image_id" => $imageId,
                "tag_name" => $tag
            ];
        }

        return $processedTags;
    }

    // image search
    public function get_image(string $id): array
    {
        $where = ["image_id" => $id];
        $orderBy = ["image_title" => "ASC"];
        return ($this->select(table: static::LORIKEET_IMAGE_TABLE, where: $where, orderBy: $orderBy)[0] ?? []);
    }

    public function search_titles(string $title, bool $exact_match = true): array
    {
        return [];
    }

    public function search_captions(string $title, bool $exact_match = true): array
    {
        return [];
    }

    public function search_tags(array $tags, bool $match_all = false): array
    {
        return [];
    }

    public function image_search(string $id = "", string $title = "", string $caption = "", array $tags = []): array
    {
        return [];
    }

    /**
     * Renders an image to the browser
     *
     * @param string $id
     * @param bool $thumbnail false
     * @return void
     */
    public function display_image(string $id, bool $thumbnail = false): void
    {
        if (!$image = $this->get_image($id)) return;

        $search = implode(
            separator: DIRECTORY_SEPARATOR,
            array: [
                $this->get_save_directory(),
                ($thumbnail) ? "{$id}_thumb.*" : "$id.*"
            ]
        );

        if (!$fullPath = (glob($search)[0] ?? false)) return;

        header(header: "Content-Type: " . mime_content_type($fullPath));
        echo file_get_contents($fullPath);
    }
}
