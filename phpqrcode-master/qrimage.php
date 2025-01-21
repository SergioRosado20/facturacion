<?php
/*
 * PHP QR Code encoder
 *
 * Image output of code using GD2
 *
 * PHP QR Code is distributed under LGPL 3
 * Copyright (C) 2010 Dominik Dzienia <deltalab at poczta dot fm>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
 */

    define('QR_IMAGE', true);

    class QRimage {

        //----------------------------------------------------------------------
        public static function png($frame, $back_color, $fore_color, $filename = false, $pixelPerPoint = 4, $outerFrame = 4, $saveandprint = FALSE)
        {
            $image = self::image($frame, $pixelPerPoint, $outerFrame, $back_color, $fore_color);

            if ($filename === false) {
                Header("Content-type: image/png");
                ImagePng($image);
            } else {
                if($saveandprint===TRUE){
                    ImagePng($image, $filename);
                    header("Content-type: image/png");
                    ImagePng($image);
                }else{
                    ImagePng($image, $filename);
                }
            }

            ImageDestroy($image);
        }

        //----------------------------------------------------------------------
        public static function jpg($frame, $filename = false, $pixelPerPoint = 8, $outerFrame = 4, $q = 85)
        {
            $image = self::image($frame, $pixelPerPoint, $outerFrame);

            if ($filename === false) {
                Header("Content-type: image/jpeg");
                ImageJpeg($image, null, $q);
            } else {
                ImageJpeg($image, $filename, $q);
            }

            ImageDestroy($image);
        }

        //----------------------------------------------------------------------
        private static function image($frame, $pixelPerPoint = 4, $outerFrame = 4, $back_color = 0xFFFFFF, $fore_color = 0x000000)
        {
            //print_r($frame);
            // Verifica que $frame no esté vacío y sea un array válido
            if (!is_array($frame) || empty($frame) || !isset($frame[0])) {
                throw new Exception('Invalid frame data provided.');
            }

            $h = count($frame);
            $w = strlen($frame[0]);

            // Agregar impresión para depuración
            //echo "Altura del frame (h): $h\n";
            //echo "Anchura del frame (w): $w\n";

            if ($h == 0 || $w == 0) {
                throw new Exception("El frame está vacío. No se puede generar el QR.");
            }

            // Asegúrate de que las dimensiones no excedan INT_MAX
            $imgW = $w + 2 * $outerFrame;
            $imgH = $h + 2 * $outerFrame;

            // Impresión de dimensiones calculadas
            //echo "Dimensiones de la imagen (imgW): $imgW, (imgH): $imgH\n";

            $imgW = max($imgW, 1);
            $imgH = max($imgH, 1);

            // Verifica que pixelPerPoint no sea cero y es un número entero positivo
            if (!is_int($pixelPerPoint) || $pixelPerPoint <= 0) {
                throw new Exception('pixelPerPoint must be a positive integer greater than zero. Current value: ' . $pixelPerPoint);
            }

            // Crea la imagen base
            $base_image = ImageCreate($imgW, $imgH);

            // Convertir el color hexadecimal a decimal
            $r1 = ($fore_color >> 16) & 0xFF;
            $g1 = ($fore_color >> 8) & 0xFF;
            $b1 = $fore_color & 0xFF;

            $r2 = ($back_color >> 16) & 0xFF;
            $g2 = ($back_color >> 8) & 0xFF;
            $b2 = $back_color & 0xFF;

            $col[0] = ImageColorAllocate($base_image, $r2, $g2, $b2);
            $col[1] = ImageColorAllocate($base_image, $r1, $g1, $b1);

            imagefill($base_image, 0, 0, $col[0]);

            // Genera el código QR
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    if ($frame[$y][$x] == '1') {
                        ImageSetPixel($base_image, $x + $outerFrame, $y + $outerFrame, $col[1]);
                    }
                }
            }

            // Crea la imagen final con el tamaño escalado
            $target_image = ImageCreate($imgW * $pixelPerPoint, $imgH * $pixelPerPoint);

            // Validar que las dimensiones no sean cero antes de copiar
            if ($imgW > 0 && $imgH > 0) {
                ImageCopyResized($target_image, $base_image, 0, 0, 0, 0, $imgW * $pixelPerPoint, $imgH * $pixelPerPoint, $imgW, $imgH);
            } else {
                throw new Exception('Invalid image dimensions for resizing. imgW: ' . $imgW . ', imgH: ' . $imgH);
            }

            ImageDestroy($base_image);

            return $target_image;
        }
    }
