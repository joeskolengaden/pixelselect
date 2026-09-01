# Makefile for the FPP "pixelselect" plugin (GPIO design selector).
#
#   make / make clean   build / remove the plugin shared library
# Override FPPDIR if FPP is not at /opt/fpp:  make FPPDIR=/path/to/fpp

PLUGIN  := pixelselect
FPPDIR  ?= /opt/fpp
SRCDIR  ?= $(FPPDIR)/src

UNAME_S := $(shell uname -s)
ifeq ($(UNAME_S),Darwin)
  SHLIB_EXT   := .dylib
  SHLIB_FLAGS := -dynamiclib -undefined dynamic_lookup
  CXX         ?= clang++
  LIBDL       :=
else
  SHLIB_EXT   := .so
  SHLIB_FLAGS := -shared
  CXX         ?= g++
  LIBDL       := -ldl   # dladdr; a no-op on glibc >= 2.34 where it lives in libc
endif

TARGET  := lib$(PLUGIN)$(SHLIB_EXT)
CXXOBJS := src/PixelSelectPlugin.o

CXXFLAGS += -std=gnu++2a -fPIC -O2 -Wall -fvisibility=default -I$(SRCDIR)

.PHONY: all clean
all: $(TARGET)

$(TARGET): $(CXXOBJS)
	$(CXX) $(SHLIB_FLAGS) -o $@ $(CXXOBJS) -lpthread $(LIBDL)

src/%.o: src/%.cpp
	$(CXX) $(CXXFLAGS) -c -o $@ $<

clean:
	rm -f $(CXXOBJS) $(TARGET)
