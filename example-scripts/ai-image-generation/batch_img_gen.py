import os
import sys
import json
import csv
from urllib import request, error
from PIL import Image
import numpy as np

# === Example Batch Image Generation in ComfyUI ===

# This script is designed to generate a batch of images using ComfyUI
# It uses a workflow template to generate the images
# The workflow template is a JSON file that contains the nodes and connections between them
# The script reads a CSV file that contains the objects to generate images for
# The script then updates the prompt text and filename for each object
# The script then queues the prompt for each object
# It's got paging so you can test run it without generating a huge number of images
# Read more at https://profitswarm.ai/using-a-gaming-pc-as-an-ai-image-generation-machine/

# Object types dictionary
IMG_OBJECT_TYPES = {
    1: 'Hire Company',
    2: 'Wedding Consultant',
    3: 'Sports Merchandise',
}

def get_object_types():
    """Returns the dictionary of object types."""
    return IMG_OBJECT_TYPES

def get_object_type_name(type_int):
    """Returns the name of the object type for the given integer."""
    return IMG_OBJECT_TYPES.get(type_int)

def slugi(text):
    """Convert text to a URL-friendly slug."""
    slug = text.lower().replace(' ', '-').replace('&', 'and').replace('(', '').replace(')', '').replace(':', '').replace(',', '').replace('.', '').replace('!', '').replace('?', '').replace('"', '').replace("'", '').replace('*', '').replace('/', '-').replace('\\', '-').replace('|', '-').replace('{', '').replace('}', '').replace('[', '').replace(']', '').replace('^', '').replace('~', '').replace('`', '').replace('@', '').replace('#', '').replace('$', '').replace('%', '').replace('^', '').replace('&', '').replace('*', '').replace('_', '-')
    while '--' in slug:
        slug = slug.replace('--', '-')
    return slug.strip('-')

def generate_seo_filename(img_obj):
    """Generate an SEO-friendly filename for an image object."""
    img_obj_type = get_object_type_name(img_obj['type'])
    
    if not img_obj_type:
        print(f"FAILED TO GET TYPE!\n{json.dumps(img_obj)}")
        return f"{img_obj['id']}_"
    
    options = [
        slugi(f"{img_obj['id']}_{img_obj['name']}"),
        slugi(f"{img_obj['id']}_{img_obj_type}-{img_obj['name']}"),
        slugi(f"{img_obj['id']}_{img_obj['name']}-{img_obj_type}"),
        slugi(f"{img_obj['id']}_{img_obj_type}-{img_obj['name']}-your-keywords"),
        slugi(f"{img_obj['id']}_{img_obj['name']}-your-keywords-{img_obj_type}"),  
    ]
    
    import random
    filename = random.choice(options)
    
    if filename:
        return f"{filename}-"
    
    return f"{img_obj['id']}_"

def queue_prompt(prompt):
    """Send the prompt to the ComfyUI server."""
    try:
        p = {"prompt": prompt}
        data = json.dumps(p).encode('utf-8')
        req = request.Request(
            "http://127.0.0.1:8188/prompt",
            data=data,
            headers={'Content-Type': 'application/json'}
        )
        response = request.urlopen(req)
        return json.loads(response.read().decode('utf-8'))
    except error.HTTPError as e:
        print(f"HTTP Error {e.code}: {e.reason}")
        if e.code == 400:
            print("This usually means there's an issue with the prompt format.")
            print("Please check that ComfyUI is running and the workflow is valid.")
        raise
    except Exception as e:
        print(f"Error sending prompt to ComfyUI: {str(e)}")
        raise

# === Read CSV lines ===
# Modify this if you have a different CSV file format
def read_csv_lines(start_line, end_line, csv_file="batch_img_gen_objects.csv"):
    """Read specific lines from the CSV file and convert to data_entries format.
    
    Args:
        start_line (int): 1-based line number to start reading from
        end_line (int): 1-based line number to end reading at
        csv_file (str): Path to the CSV file
        
    Returns:
        list: List of data entries in the format used by the script
    """
    data_entries = []
    try:
        with open(csv_file, 'r', encoding='utf-8') as f:
            # Skip lines before start_line
            for _ in range(start_line - 1):
                next(f)
            
            # Read the requested lines
            for i, line in enumerate(f, start=start_line):
                if i > end_line:
                    break
                    
                # Parse CSV line
                reader = csv.reader([line])
                for row in reader:
                    if len(row) >= 4:
                        data_entries.append({
                            "id": int(row[0].strip('"')),
                            "type": int(row[1].strip('"')),
                            "name": row[2].strip('"'),
                            "short_desc": row[3].strip('"')
                        })
    
    except Exception as e:
        print(f"Error reading CSV file: {str(e)}")
        return []
    
    return data_entries

# === Load base workflow template ===
with open("workflow_template.json", "r") as f:
    template = json.load(f)

# === Your input data ===
base_prompt = "{{prompt_insert}}: Generate a black and white illustration, (a quirky art style; minimalistic but professional) for the following {{prompt_insert}}. Include a human and objects related to {{name}}. Include no text in the image."

# Read data entries from CSV
data_entries = read_csv_lines(1, 100)  # Example: read first 10 lines

print("🚀 Starting ComfyUI batch generation...")

for entry in data_entries:
    print(f"\n🔧 Processing: {entry['name']}")
    
    # Create a copy of the template
    prompt = json.loads(json.dumps(template))
    
    # Update the prompt text and filename
    for node_id, node in prompt.items():
        if node["class_type"] == "CLIPTextEncode" and "Positive" in node.get("_meta", {}).get("title", ""):
            prompt_text = base_prompt.replace("{{prompt_insert}}",get_object_type_name(entry["type"]) + ": " + entry["name"] + " (" + entry["short_desc"] + ")")
            prompt_text = prompt_text.replace("{{object_name}}",entry["name"])
            prompt_text = prompt_text.replace("{{object_type}}",get_object_type_name(entry["type"]))
            node["inputs"]["text"] = prompt_text
            print(f"Prompt text: {prompt_text}")
        
        if node["class_type"] == "SaveImage":
            node["inputs"]["filename_prefix"] = generate_seo_filename(entry)
    
    # Queue the prompt
    try:
        response = queue_prompt(prompt)
        print(f"✅ Queued prompt for {entry['name']}")
        print(f"Prompt ID: {response.get('prompt_id', 'unknown')}")
    except Exception as e:
        print(f"❌ Error queuing prompt for {entry['name']}: {str(e)}")
        print("Skipping to next entry...")
        continue

print("\n✅ All prompts queued! Check ComfyUI for progress.")