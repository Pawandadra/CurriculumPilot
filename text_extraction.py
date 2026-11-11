import fitz
import cv2
import numpy as np
import pytesseract
from PIL import Image
from pdf2image import convert_from_path
import os

def process_image_refined(img):
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    gray = cv2.resize(gray, None, fx=2, fy=2, interpolation=cv2.INTER_CUBIC)

    denoised = cv2.bilateralFilter(gray, 9, 75, 75)
    _, thresh = cv2.threshold(denoised, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)

    kernel = np.ones((2, 2), np.uint8)
    processed = cv2.morphologyEx(thresh, cv2.MORPH_CLOSE, kernel)

    return processed

def ocr_page(page_image):
    img_cv = cv2.cvtColor(np.array(page_image), cv2.COLOR_RGB2BGR)
    prepped = process_image_refined(img_cv)

    config = '--oem 3 --psm 6'
    text = pytesseract.image_to_string(prepped, config=config)
    return text

def industrial_pdf_extractor(pdf_path, output_txt):
    doc = fitz.open(pdf_path)
    final_text = ""

    for i in range(len(doc)):
        page = doc[i]
        digital_text = page.get_text("text").strip()

        if len(digital_text) > 50:
            print(f"Page {i + 1}: Using Direct Extraction (100% Accuracy)")
            final_text += f"\n{digital_text}"
        else:
            print(f"Page {i + 1}: No text layer found. Running Tesseract...")
            images = convert_from_path(pdf_path, dpi=300, first_page=i + 1, last_page=i + 1)
            if images:
                page_text = ocr_page(images[0])
                final_text += f"\n{page_text}"

    with open(output_txt, "w", encoding="utf-8") as f:
        f.write(final_text)

    return final_text
