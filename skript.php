<?php

function processJsonFile($filePath, &$output, $test, $maxItems) {
    $data = json_decode(file_get_contents($filePath), true);
    if (!is_array($data) || !isset($data["vehicle"]) || !isset($data["categories"])) {
        echo "Skipping file $filePath: not in expected format\n";
        return;
    }
    $vehicleName = $data["vehicle"]["name"];
    foreach ($data["categories"] as $category) {
        extractSpareParts($category, [$vehicleName, $category["name"]], $output, $test, $maxItems);
    }
}

function extractSpareParts($data, $parentNames, &$output, $test, $maxItems) {
    if ($test && count($output) >= $maxItems) {
        return;
    }

    if (isset($data["spare_parts"])) {
        foreach ($data["spare_parts"] as $part) {
            if ($test && count($output) >= $maxItems) {
                return;
            }
            $partName = $part["product"]["name"];
            $partNo = $part["product"]["product_no"];
            $vatPercent = isset($part["product"]["vat_percent"]) ? $part["product"]["vat_percent"] : 0;
            $unitPriceInclVat = isset($part["product"]["unit_price_incl_vat"]) ? $part["product"]["unit_price_incl_vat"] : 0;
            if (in_array(null, $parentNames) || $partName === null || $partNo === null) {
                continue;
            }
            $categoryPath = implode(" > ", $parentNames);
            if (isset($output[$partNo])) {
                $output[$partNo]["categories"][] = $categoryPath;
            } else {
                $output[$partNo] = [
                    "name" => $partName,
                    "categories" => [$categoryPath],
                    "vat_percent" => $vatPercent,
                    "unit_price_incl_vat" => $unitPriceInclVat
                ];
            }
        }
    }

    if (isset($data["categories"])) {
        foreach ($data["categories"] as $category) {
            extractSpareParts($category, array_merge($parentNames, [$category["name"]]), $output, $test, $maxItems);
        }
    }
}

function createXml($output, $xmlFile) {
    $xml = new SimpleXMLElement('<SHOP/>');
    foreach ($output as $partNo => $info) {
        $shopItem = $xml->addChild('SHOPITEM');
        $shopItem->addChild('NAME', htmlspecialchars($info["name"]));
        $shopItem->addChild('CODE', htmlspecialchars($partNo));
        $categoriesElem = $shopItem->addChild('CATEGORIES');
        foreach ($info["categories"] as $categoryPath) {
            $parts = explode(" > ", $categoryPath, 2);
            if (count($parts) > 1) {
                $mainCategory = str_replace(" / ", " > ", $parts[0]);
                $subCategory = $parts[1];
                $category = $categoriesElem->addChild('CATEGORY', htmlspecialchars("$mainCategory > $subCategory"));
            } else {
                $category = $categoriesElem->addChild('CATEGORY', htmlspecialchars($parts[0]));
            }
        }
        $shopItem->addChild('PRICE_VAT', htmlspecialchars($info["unit_price_incl_vat"]));
        $shopItem->addChild('VAT', htmlspecialchars($info["vat_percent"]));
    }

    $dom = dom_import_simplexml($xml)->ownerDocument;
    $dom->formatOutput = true;
    file_put_contents($xmlFile, $dom->saveXML());
}

function main($folderPath, $xmlFile, $test = false, $maxItems = 10) {
    $output = [];
    foreach (scandir($folderPath) as $filename) {
        if (pathinfo($filename, PATHINFO_EXTENSION) === 'json') {
            $filePath = $folderPath . DIRECTORY_SEPARATOR . $filename;
            processJsonFile($filePath, $output, $test, $maxItems);
            if ($test && count($output) >= $maxItems) {
                break;
            }
        }
    }
    createXml($output, $xmlFile);
}

$folderPath = 'spare_parts_feed';  // Path to the folder with data
$xmlFile = 'output.xml';  // Name of the output XML file
$test = false;  // True for testing XML validation (generates only 10 items)
main($folderPath, $xmlFile, $test);

?>
