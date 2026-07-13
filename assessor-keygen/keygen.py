import tkinter as tk
from tkinter import ttk, messagebox
import hashlib

SECRET_SALT = "AssessorSecretKey2026!"

def generate_key():
    hardware_id = hardware_id_var.get().strip()
    if not hardware_id:
        messagebox.showerror("Error", "Please enter a Hardware ID")
        return
        
    hash_obj = hashlib.sha256((hardware_id + SECRET_SALT).encode('utf-8'))
    activation_key = hash_obj.hexdigest()[:16].upper()
    
    activation_key_var.set(activation_key)

def copy_key():
    key = activation_key_var.get()
    if key:
        root.clipboard_clear()
        root.clipboard_append(key)
        root.update()
        messagebox.showinfo("Success", "Activation Key copied to clipboard!")

root = tk.Tk()
root.title("Assessor Hardware Keygen")
root.geometry("400x250")
root.resizable(False, False)
root.eval('tk::PlaceWindow . center')

style = ttk.Style()
style.theme_use('clam')

frame = ttk.Frame(root, padding="20")
frame.pack(fill=tk.BOTH, expand=True)

# Hardware ID Input
ttk.Label(frame, text="Hardware ID:", font=("Segoe UI", 10, "bold")).pack(anchor=tk.W, pady=(0, 5))
hardware_id_var = tk.StringVar()
hw_entry = ttk.Entry(frame, textvariable=hardware_id_var, font=("Segoe UI", 12), width=30)
hw_entry.pack(fill=tk.X, pady=(0, 15))

# Generate Button
gen_btn = ttk.Button(frame, text="Generate Activation Key", command=generate_key)
gen_btn.pack(fill=tk.X, pady=(0, 15), ipady=5)

# Activation Key Output
ttk.Label(frame, text="Activation Key:", font=("Segoe UI", 10, "bold")).pack(anchor=tk.W, pady=(0, 5))
activation_key_var = tk.StringVar()
key_entry = ttk.Entry(frame, textvariable=activation_key_var, font=("Segoe UI", 14, "bold"), width=30, state="readonly")
key_entry.pack(fill=tk.X, pady=(0, 10))

# Copy Button
copy_btn = ttk.Button(frame, text="Copy Key", command=copy_key)
copy_btn.pack(fill=tk.X, ipady=3)

root.mainloop()
