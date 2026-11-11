import json
import re
from thefuzz import fuzz
from thefuzz import process

def split_chapters_fuzzy(toc_json_path, full_text_path, output_json_path):
    """
    Split a book's text into chapters using fuzzy matching based on the titles
    provided in a table of contents (JSON). Saves the results back to JSON.
    """

    with open(toc_json_path, 'r', encoding='utf-8') as toc_file:
        toc_data = json.load(toc_file)

    with open(full_text_path, 'r', encoding='utf-8') as text_file:
        full_text = text_file.read()

    chapters = []
    cursor = 0

    for entry in toc_data:
        title = entry['title']
        window = full_text[cursor: cursor + 20000]
        window_lines = window.split('\n')

        match = process.extractOne(title, window_lines, scorer=fuzz.token_set_ratio)
        if match and match[1] > 75:
            matched_line = match[0]
            start_pos = full_text.find(matched_line, cursor)

            if chapters:
                chapters[-1]['content'] = full_text[chapters[-1]['_start_pos']: start_pos].strip()

            chapters.append({
                "title": title,
                "_start_pos": start_pos,
                "content": ""
            })

            cursor = start_pos + len(matched_line)
            print(f"Found: '{title}' (Matched as: '{matched_line}' Score: {match[1]})")
        else:
            print(f"Skipped: '{title}' (Not found in book text)")

    if chapters:
        chapters[-1]['content'] = full_text[chapters[-1]['_start_pos']:].strip()

    for chapter in chapters:
        if '_start_pos' in chapter:
            del chapter['_start_pos']

    with open(output_json_path, 'w', encoding='utf-8') as out_file:
        json.dump(chapters, out_file, indent=4)

    return chapters
