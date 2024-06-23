const fs = require('fs').promises;
const path = require('path');
const xmlbuilder = require('xmlbuilder');

async function processJsonFile(filePath, output, test, maxItems) {
    try {
        const data = await fs.readFile(filePath, 'utf-8');
        const jsonData = JSON.parse(data);

        if (typeof jsonData !== 'object' || !jsonData.vehicle || !jsonData.categories) {
            console.log(`Skipping file ${filePath}: not in expected format`);
            return output;
        }

        const vehicleName = jsonData.vehicle.name;
        for (const category of jsonData.categories) {
            output = await extractSpareParts(category, [vehicleName, category.name], output, test, maxItems);
        }
    } catch (err) {
        console.error(`Error processing file ${filePath}: ${err}`);
    }
    return output;
}

async function extractSpareParts(data, parentNames, output, test, maxItems) {
    if (test && Object.keys(output).length >= maxItems) {
        return output;
    }

    if (data.spare_parts) {
        for (const part of data.spare_parts) {
            if (test && Object.keys(output).length >= maxItems) {
                return output;
            }

            const partName = part.product.name;
            const partNo = part.product.product_no;
            const vatPercent = part.product.vat_percent || 0;
            const unitPriceInclVat = part.product.unit_price_incl_vat || 0;

            if (parentNames.includes(null) || !partName || !partNo) {
                continue;
            }

            const categoryPath = parentNames.join(' > ');
            if (output[partNo]) {
                output[partNo].categories.push(categoryPath);
            } else {
                output[partNo] = {
                    name: partName,
                    categories: [categoryPath],
                    vat_percent: vatPercent,
                    unit_price_incl_vat: unitPriceInclVat
                };
            }
        }
    }

    if (data.categories) {
        for (const category of data.categories) {
            output = await extractSpareParts(category, parentNames.concat([category.name]), output, test, maxItems);
        }
    }

    return output;
}

function createXml(output, xmlFile) {
    const shop = xmlbuilder.create('SHOP');

    for (const partNo in output) {
        const info = output[partNo];
        const shopItem = shop.ele('SHOPITEM');
        shopItem.ele('NAME', info.name);
        shopItem.ele('CODE', partNo);

        const categoriesElem = shopItem.ele('CATEGORIES');
        info.categories.forEach(categoryPath => {
            const parts = categoryPath.split(' > ', 2);
            if (parts.length > 1) {
                const mainCategory = parts[0].replace(' / ', ' > ');
                const subCategory = parts[1];
                categoriesElem.ele('CATEGORY', `${mainCategory} > ${subCategory}`);
            } else {
                categoriesElem.ele('CATEGORY', parts[0]);
            }
        });

        shopItem.ele('PRICE_VAT', info.unit_price_incl_vat);
        shopItem.ele('VAT', info.vat_percent);
    }

    const xmlString = shop.end({ pretty: true, indent: '  ', newline: '\n' });
    return fs.writeFile(xmlFile, xmlString, 'utf-8');
}

async function main(folderPath, xmlFile, test = false, maxItems = 10) {
    let output = {};
    try {
        const files = await fs.readdir(folderPath);

        for (const file of files) {
            if (file.endsWith('.json')) {
                const filePath = path.join(folderPath, file);
                output = await processJsonFile(filePath, output, test, maxItems);
                if (test && Object.keys(output).length >= maxItems) {
                    break;
                }
            }
        }

        await createXml(output, xmlFile);
        console.log(`XML file ${xmlFile} has been created successfully.`);
    } catch (err) {
        console.error(`Error in main function: ${err}`);
    }
}

const folderPath = 'spare_parts_feed';  // Path to the folder with data
const xmlFile = 'output.xml';  // Name of the output XML file
const test = false;  // True for testing XML validation (generates only 10 items)
main(folderPath, xmlFile, test);
