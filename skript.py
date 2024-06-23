import json
import os
import xml.etree.ElementTree as ET
from xml.dom import minidom

def process_json_file(file_path, output, test, max_items):
    with open(file_path, 'r', encoding='utf-8') as file:
        data = json.load(file)
        if not isinstance(data, dict) or "vehicle" not in data or "categories" not in data:
            print(f"Skipping file {file_path}: not in expected format")
            return
        vehicle_name = data["vehicle"]["name"]
        for category in data["categories"]:
            extract_spare_parts(category, [vehicle_name, category["name"]], output, test, max_items)

def extract_spare_parts(data, parent_names, output, test, max_items):
    if test and len(output) >= max_items:
        return

    if "spare_parts" in data:
        for part in data["spare_parts"]:
            if test and len(output) >= max_items:
                return
            part_name = part["product"]["name"]
            part_no = part["product"]["product_no"]
            vat_percent = part["product"].get("vat_percent", 0)
            unit_price_incl_vat = part["product"].get("unit_price_incl_vat", 0) or 0
            if None in parent_names or part_name is None or part_no is None:
                continue
            category_path = " > ".join(parent_names)
            if part_no in output:
                output[part_no]["categories"].append(category_path)
            else:
                output[part_no] = {
                    "name": part_name,
                    "categories": [category_path],
                    "vat_percent": vat_percent,
                    "unit_price_incl_vat": unit_price_incl_vat
                }
    
    if "categories" in data:
        for category in data["categories"]:
            extract_spare_parts(category, parent_names + [category["name"]], output, test, max_items)

def create_xml(output, xml_file):
    shop = ET.Element("SHOP")
    for part_no, info in output.items():
        shop_item = ET.SubElement(shop, "SHOPITEM")
        name = ET.SubElement(shop_item, "NAME")
        name.text = info["name"]
        code = ET.SubElement(shop_item, "CODE")
        code.text = part_no
        categories_elem = ET.SubElement(shop_item, "CATEGORIES")
        for category_path in info["categories"]:
            parts = category_path.split(" > ", 1)
            if len(parts) > 1:
                main_category = parts[0].replace(" / ", " > ")
                sub_category = parts[1]
                category = ET.SubElement(categories_elem, "CATEGORY")
                category.text = f"{main_category} > {sub_category}"
            else:
                category = ET.SubElement(categories_elem, "CATEGORY")
                category.text = parts[0]
        
        price_vat = ET.SubElement(shop_item, "PRICE_VAT")
        price_vat.text = str(info["unit_price_incl_vat"])
        vat = ET.SubElement(shop_item, "VAT")
        vat.text = str(info["vat_percent"])

    pretty_xml = prettify_xml(shop)
    with open(xml_file, 'w', encoding='utf-8') as file:
        file.write(pretty_xml)

def prettify_xml(elem):
    rough_string = ET.tostring(elem, 'utf-8')
    reparsed = minidom.parseString(rough_string)
    return reparsed.toprettyxml(indent="  ")

def main(folder_path, xml_file, test=False, max_items=10):
    output = {}
    for filename in os.listdir(folder_path):
        if filename.endswith(".json"):
            file_path = os.path.join(folder_path, filename)
            process_json_file(file_path, output, test, max_items)
            if test and len(output) >= max_items:
                break

    create_xml(output, xml_file)

if __name__ == "__main__":
    folder_path = 'spare_parts_feed'  # Path to the folder with data
    xml_file = 'output.xml'  # Name of the output XML file
    test = False  # True for testing XML validation (generates only 10 items)
    main(folder_path, xml_file, test)
